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
    product_list: [],
    page: 1,
    nomore: false,
    loadpage: false,
    categoryShowIndicator: false,
    categoryScrollPercent: 0,
    indicatorDots: true,
    indicatorColor: 'rgba(0, 0, 0, .3)',
    indicatorActiveColor: '#FF0000',
    autoplay: true,
    interval: 5000,
    duration: 500,
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
      .get(route('index'), {})
      .then(function (data) {
        const categories = data && Array.isArray(data.product_category) ? data.product_category : []
        that.setData({
          index: data || {},
          categoryShowIndicator: categories.length > 10,
          categoryScrollPercent: 0,
        })
        that.updateCategoryScrollState()
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })

    that.loadProducts()
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  onShow() {
    cartStore.refresh()
  },

  onReady() {
    const that = this

    authStore.restore().then(function () {
      if (authStore.is_work) {
        wx.navigateTo({ url: '/pages/work/work' })
      }
    })

    that.updateCategoryScrollState()
  },

  /**
   * 缓存分类横滑视口/内容宽度，供进度条计算
   */
  updateCategoryScrollState() {
    const that = this

    wx.nextTick(function () {
      const query = wx.createSelectorQuery()
      query.select('.menu .menu-scroll').boundingClientRect()
      query.select('.menu .menu-list').boundingClientRect()
      query.exec(function (res: any) {
        const scrollRect = res && res[0] ? res[0] : null
        const listRect = res && res[1] ? res[1] : null
        if (!scrollRect || !listRect) {
          return
        }

        that._categoryViewWidth = scrollRect.width || 0
        that._categoryContentWidth = listRect.width || 0
      })
    })
  },

  /**
   * 分类横滑时更新底部进度条位置（0–60，相对轨道可移动区间）
   */
  onCategoryScroll(e: WechatMiniprogram.CustomEvent) {
    const scrollLeft = Math.max(0, e.detail.scrollLeft || 0)
    const viewWidth = this._categoryViewWidth || 0
    const scrollWidth = Math.max(
      e.detail.scrollWidth || 0,
      this._categoryContentWidth || 0
    )
    const maxScroll = scrollWidth - viewWidth

    if (viewWidth <= 0 || maxScroll <= 0) {
      return
    }

    // 拇指宽度约占轨道的 40%（16/40），可移动区间为剩余 60%
    const ratio = Math.min(1, Math.max(0, scrollLeft / maxScroll))
    const percent = ratio * 60

    this.setData({ categoryScrollPercent: percent })
  },

  /**
   * 首页商品列表（全部分类，分页，触底追加）
   * @param concat 是否追加
   * @param page 页码；空则用 data.page
   */
  loadProducts(concat = false, page: number | string = '') {
    const that = this

    http
      .get(route('product'), {
        id: 0,
        page: page ? page : that.data.page,
      })
      .then(function (data) {
        let dataBox
        const dataNew = data && data.product_list ? data.product_list : []

        if (concat) {
          dataBox = that.data.product_list
          if (Array.isArray(dataNew) && dataNew.length > 0) {
            dataBox = dataBox.concat(dataNew)
          } else {
            that.setData({ nomore: true })
          }
        } else {
          dataBox = Array.isArray(dataNew) ? dataNew : []
        }

        that.setData({
          product_list: dataBox,
          loadpage: false,
        })
      })
      .catch(function (err) {
        that.setData({ loadpage: false })
        douMsg(err.message || 'request_failed')
      })
  },

  onReachBottom() {
    const that = this
    if (!that.data.nomore && !that.data.loadpage) {
      const page = that.data.page + 1
      that.setData({ page, loadpage: true })
      setTimeout(function () {
        that.loadProducts(true)
      }, 1000)
    }
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
})
