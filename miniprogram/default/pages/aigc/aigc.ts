// pages/aigc/aigc.ts AI应用中心
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { commonStore } from '../../stores/index.js'
import { getApps } from '../../services/aigc.js'
import { hasRoute } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    app_list: [],
    loading: false,
  } as Record<string, any>,

  onLoad() {
    const that = this

    that.setData({ title: pageTitle('aigc') })
    wx.setStorageSync('shareTitle', pageTitle('aigc'))

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })
  },

  onShow() {
    this.loadData()
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  onPullDownRefresh() {
    this.loadData(true)
  },

  loadData(refresh = false) {
    const that = this

    if (!hasRoute('aigc')) {
      that.setData({ app_list: [], loading: false })
      if (refresh) wx.stopPullDownRefresh()
      return
    }

    that.setData({ loading: true })
    getApps()
      .then(function (data) {
        that.setData({ app_list: data.app_list || [], loading: false })
      })
      .catch(function (err) {
        that.setData({ loading: false })
        douMsg(err.message || 'request_failed')
      })
      .then(function () {
        if (refresh) wx.stopPullDownRefresh()
      })
  },

  openApp(e: WechatMiniprogram.TouchEvent) {
    const item = e.currentTarget.dataset.item || {}
    if (!item.slug) return
    const page = item.type === 'chat' ? 'chat' : 'app'
    wx.navigateTo({ url: '/pages/aigc/' + page + '?slug=' + encodeURIComponent(item.slug) })
  },

  toWorks() {
    wx.navigateTo({ url: '/pages/aigc/works' })
  },

  toPackage() {
    wx.navigateTo({ url: '/pages/aigc/package' })
  },

  toUser() {
    wx.navigateTo({ url: '/pages/aigc/user' })
  },

  onShareAppMessage() {
    return { title: wx.getStorageSync('shareTitle') }
  },
})
