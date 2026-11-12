// pages/aigc/user.ts 我的AI应用
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { getUser } from '../../services/aigc.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    quota_status: null,
    subscription_list: [],
  } as Record<string, any>,

  onLoad() {
    this.setData({ title: pageTitle('aigc_user') })
    wx.setStorageSync('shareTitle', pageTitle('aigc_user'))
    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })
  },

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      that.loadData()
    })
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadData() {
    const that = this

    getUser()
      .then(function (data) {
        that.setData({
          quota_status: data.quota_status || null,
          subscription_list: data.subscription_list || [],
        })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  toPackage() {
    wx.navigateTo({ url: '/pages/aigc/package' })
  },

  toWorks() {
    wx.navigateTo({ url: '/pages/aigc/works' })
  },

  toApps() {
    wx.navigateTo({ url: '/pages/aigc/aigc' })
  },

  onShareAppMessage() {
    return { title: wx.getStorageSync('shareTitle') }
  },
})
