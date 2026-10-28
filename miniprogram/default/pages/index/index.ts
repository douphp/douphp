// index.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

const app = getApp<IAppOption>()

// navbar 是 fixed 的，与 header 一起遮挡视口顶部，定位与高亮必须一并扣除
const NAV_HEIGHT = Number(app.globalData.navigationBarAndStatusBarHeight) || 0

Page({
  data: {
    navigationBarAndStatusBarHeight: app.globalData.navigationBarAndStatusBarHeight + 'px',
    index: {},
    navHeight: NAV_HEIGHT,
    curMenu: 'main',
    headerHeight: 156,
    scrollLock: false,
    current: 0,
    indicatorDots: true,
    indicatorColor: 'rgba(0, 0, 0, .3)',
    indicatorActiveColor: '#FF0000',
    autoplay: true,
    interval: 5000,
    duration: 500,
    imgheight: '',
    locationMode: 'fold',
  } as Record<string, any>,

  onLoad() {
    const that = this

    that.setData({ title: pageTitle('site_name') })

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features', 'nav_list'],
    })

    http
      .get(route('index'), {
      })
      .then(function (data) {
        that.setData({ index: data || {} })
        // 数据渲染完成后重新测量，避免首测早于接口返回导致锚点定位偏移
        wx.nextTick(function () {
          that.measureSections()
        })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })

    that.setData({ windowHeight: wx.getWindowInfo().windowHeight })
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  onShow() {
    // tabBar 购物车角标由 cartStore.badge 统一驱动
    cartStore.refresh()
  },

  onReady() {
    const that = this

    // 延迟测量，防止获取节点错误（接口数据返回后还会再次测量）
    setTimeout(function () {
      that.measureSections()
    }, 1000)

    authStore.restore().then(function () {
      if (authStore.is_work) {
        wx.navigateTo({ url: '/pages/work/work' })
      }
    })
  },

  /**
   * 测量 header 高度与各锚点区块位置，供滚动高亮与 scrollTo 定位使用
   * 可重复调用：接口数据到位、轮播图加载后都要重测
   */
  measureSections() {
    const that = this
    const query = wx.createSelectorQuery()

    query.select('.header').boundingClientRect()
    query.selectAll('.scroll-box').boundingClientRect()
    query.selectViewport().scrollOffset()

    query.exec(function (res: any) {
      const headerRect = res && res[0] ? res[0] : null
      const rects = res && res[1] ? res[1] : []
      const viewport = res && res[2] ? res[2] : null
      // boundingClientRect 的 top 相对视口，叠加 scrollTop 换算为文档坐标
      const offsetTop = viewport ? viewport.scrollTop : 0

      if (headerRect && Math.abs(headerRect.height - that.data.headerHeight) >= 1) {
        that.setData({ headerHeight: headerRect.height })
      }

      if (!rects || !rects.length) {
        return
      }

      const dataOrg: any[] = []
      const dataBox: Record<string, any> = {}

      rects.forEach(function (rect: any) {
        if (!rect || !rect.id) {
          return
        }
        const item = {
          id: rect.id,
          top: rect.top + offsetTop,
          bottom: rect.bottom + offsetTop,
        }
        dataOrg.push(item)
        dataBox[rect.id] = item
      })

      // 仅供逻辑层使用，不走 setData：数组字符串下标经序列化会丢失，也没必要渲染
      ;(that as any)._org = dataOrg
      ;(that as any)._box = dataBox
    })
  },

  onPageScroll(e: WechatMiniprogram.Page.IPageScrollOption) {
    const that = this
    const org: any[] = (that as any)._org || []
    // 与 scrollTo 使用同一个遮挡高度，避免高亮整体延迟一个区块
    const hideHeight = that.data.headerHeight + that.data.navHeight

    if (!that.data.scrollLock && org.length) {
      // 取「最后一个已到达其顶部的区块」，避免恰好落在边界时一个都命中不了
      let active = ''
      for (let i = 0; i < org.length; i++) {
        if (e.scrollTop + hideHeight >= org[i].top - 1) {
          active = org[i].id
        } else {
          break
        }
      }
      if (active && active !== that.data.curMenu) {
        that.setData({ curMenu: active })
      }
    }

    if (that.data.scrollLock) {
      return
    }

    // footer 检测：滚动中每帧查节点开销大，按 100ms 节流
    const state = that as any
    const now = Date.now()
    const lastCheck = state._footerCheckAt || 0
    if (now - lastCheck < 100) {
      return
    }
    state._footerCheckAt = now

    wx.createSelectorQuery()
      .select('.footer')
      .boundingClientRect(function (res: any) {
        if (!res || !org.length) {
          return
        }
        if (res.bottom.toFixed(0) < that.data.windowHeight + 5) {
          const last = org[org.length - 1].id
          if (that.data.curMenu !== last) {
            that.setData({ curMenu: last })
          }
        }
      })
      .exec()
  },

  scrollTo(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const id = e.currentTarget.dataset.id
    const box = (that as any)._box ? (that as any)._box[id] : null

    // 节点测量未就绪时忽略点击，避免空指针
    if (!box) {
      return
    }

    // 目标区块顶部要落在「navbar + header」之下，否则标题会被固定头部压住
    const scrollTop = Math.max(box.top - that.data.headerHeight - that.data.navHeight, 0)

    // 动画期间锁高亮，否则途经的区块会把 curMenu 抖成中间项
    // 用定时器解锁而不依赖 complete，避免极端情况下锁死导致高亮彻底失效
    clearTimeout((that as any)._unlockTimer)
    that.setData({ curMenu: id, scrollLock: true })
    ;(that as any)._unlockTimer = setTimeout(function () {
      that.setData({ scrollLock: false })
    }, 400)

    wx.pageScrollTo({
      scrollTop,
      duration: 300,
    })
  },

  locationAction() {
    this.setData({
      locationMode: this.data.locationMode === 'fold' ? 'pop' : 'fold',
    })
  },

  imageLoad(e: WechatMiniprogram.CustomEvent) {
    const that = this
    const windowWidth = wx.getWindowInfo().windowWidth
    const height = e.detail.height
    const width = e.detail.width
    const imgheight = (windowWidth * height) / width + 'px'

    this.setData({ imgheight }, function () {
      // 轮播高度变化会把后续区块整体下推，必须重测；多张图加载合并成一次
      clearTimeout((that as any)._measureTimer)
      ;(that as any)._measureTimer = setTimeout(function () {
        that.measureSections()
      }, 60)
    })
  },

  // 受控 current 只做同步，首尾衔接交给 swiper 的 circular，不再手动回跳
  swiperbindchange(e: WechatMiniprogram.CustomEvent) {
    if (e.detail.source === 'touch' || e.detail.source === 'autoplay') {
      this.setData({ current: e.detail.current })
    }
  },

  douCategory(e: WechatMiniprogram.TouchEvent) {
    wx.setStorageSync('product_category_id', e.currentTarget.dataset.category_id)
    const url = e.currentTarget.dataset.url
    wx.switchTab({ url })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    const url = e.currentTarget.dataset.url
    wx.navigateTo({ url })
  },

  douSwitchTab(e: WechatMiniprogram.TouchEvent) {
    const url = e.currentTarget.dataset.url
    wx.switchTab({ url })
  },

  catSwitchTab(e: WechatMiniprogram.TouchEvent) {
    wx.setStorageSync('category_id', e.currentTarget.dataset.category_id)
    const url = e.currentTarget.dataset.url
    wx.switchTab({ url })
  },
})
