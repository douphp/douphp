// pages/chat/package.ts AI套餐
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    package_list: [],
    quota_status: null,
  } as Record<string, any>,

  onLoad() {
    const that = this

    that.setData({ title: pageTitle('chat_package') })
    wx.setStorageSync('shareTitle', pageTitle('chat_package'))

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    that.loadData()
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadData() {
    const that = this

    http
      .get(route('chat.package'))
      .then(function (data) {
        that.setData({
          package_list: data.package_list || [],
          quota_status: data.quota_status || null,
        })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  addToCart(e: WechatMiniprogram.TouchEvent) {
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('order.cart.store'), {
          module: 'chat_package',
          item_id: e.currentTarget.dataset.item_id,
        })
        .then(function () {
          wx.navigateTo({ url: '/pages/order/checkout' })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  onShareAppMessage() {
    return { title: wx.getStorageSync('shareTitle') }
  },
})
