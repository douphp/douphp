// pages/chat/list.ts AI助手应用中心
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

const app = getApp<IAppOption>()

Page({
  data: {
    app_list: [],
  } as Record<string, any>,

  onLoad() {
    const that = this

    that.setData({ title: pageTitle('chat_list') })
    wx.setStorageSync('shareTitle', pageTitle('chat_list'))

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

  loadData() {
    const that = this

    http
      .get(route('chat.list'))
      .then(function (data) {
        that.setData({
          app_list: data.app_list || [],
        })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  openApp(e: WechatMiniprogram.TouchEvent) {
    const item = e.currentTarget.dataset.item
    wx.navigateTo({ url: '/pages/chat/app?slug=' + encodeURIComponent(item.slug) })
  },

  toPackage() {
    wx.navigateTo({ url: '/pages/chat/package' })
  },

  onShareAppMessage() {
    return { title: wx.getStorageSync('shareTitle') }
  },
})
