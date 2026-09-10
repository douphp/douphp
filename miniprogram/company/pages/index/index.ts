// index.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

const app = getApp<IAppOption>()

Page({
  data: {
    navigationBarAndStatusBarHeight: app.globalData.navigationBarAndStatusBarHeight + 'px',
    index: {},
    dataBox: [],
    curMenu: 'main',
    headerHeight: 156,
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

    // 延迟加载框架，防止获取节点错误
    setTimeout(function () {
      const query = wx.createSelectorQuery()

      query
        .select('.header')
        .boundingClientRect(function (res) {
          if (res) {
            that.setData({ headerHeight: res.height })
          }
        })
        .exec()

      query
        .selectAll('.scroll-box')
        .boundingClientRect(function (rects: any) {
          if (rects && rects.length) {
            const dataBox: any[] = []
            rects.forEach(function (rect: any) {
              dataBox[rect.id as any] = rect
            })
            that.setData({ dataBox, dataOrg: rects })
          }
        })
        .exec()
    }, 1000)

    authStore.restore().then(function () {
      if (authStore.is_work) {
        wx.navigateTo({ url: '/pages/work/work' })
      }
    })
  },

  onPageScroll(e: WechatMiniprogram.Page.IPageScrollOption) {
    const that = this

    for (let i = 0; i < that.data.dataOrg.length; i++) {
      if (
        e.scrollTop > that.data.dataOrg[i].top - that.data.headerHeight &&
        e.scrollTop < this.data.dataOrg[i].bottom - that.data.headerHeight &&
        !that.data.scrollBottom
      ) {
        that.setData({ curMenu: that.data.dataOrg[i].id })
        break
      }
    }

    wx.createSelectorQuery()
      .select('.footer')
      .boundingClientRect(function (res: any) {
        if (res.bottom.toFixed(0) < that.data.windowHeight + 5) {
          that.setData({
            curMenu: that.data.dataOrg[that.data.dataOrg.length - 1].id,
          })
        }
      })
      .exec()
  },

  scrollTo(e: WechatMiniprogram.TouchEvent) {
    const that = this

    wx.pageScrollTo({
      scrollTop: that.data.dataBox[e.currentTarget.dataset.id].top - that.data.headerHeight,
      duration: 0,
    })

    that.setData({ curMenu: e.currentTarget.dataset.id })
  },

  locationAction() {
    this.setData({
      locationMode: this.data.locationMode === 'fold' ? 'pop' : 'fold',
    })
  },

  imageLoad(e: WechatMiniprogram.CustomEvent) {
    const windowWidth = wx.getWindowInfo().windowWidth
    const height = e.detail.height
    const width = e.detail.width
    const imgheight = (windowWidth * height) / width + 'px'
    this.setData({ imgheight })
  },

  swiperbindchange(e: WechatMiniprogram.CustomEvent) {
    if (e.detail.source === 'touch' || e.detail.source === 'autoplay') {
      this.setData({ current: e.detail.current })

      if (this.data.current === 0 && this.data.rightcurrent > 1) {
        this.setData({ current: this.data.rightcurrent })
      } else {
        this.setData({ rightcurrent: this.data.current })
      }
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
