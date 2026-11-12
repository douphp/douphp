// pages/aigc/package.ts AI套餐
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { getPackages, getUser } from '../../services/aigc.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { startQuoteCheckout } from '../../utils/checkout.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    package_list: [],
    quota_status: null,
  } as Record<string, any>,

  onLoad() {
    this.setData({ title: pageTitle('aigc_package') })
    wx.setStorageSync('shareTitle', pageTitle('aigc_package'))
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

    getPackages()
      .then(function (data) {
        that.setData({ package_list: data.package_list || [] })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })

    if (wx.getStorageSync('api_token')) {
      getUser()
        .then(function (data) {
          that.setData({ quota_status: data.quota_status || null })
        })
        .catch(function () {
          /* 未登录或接口不可用时忽略配额展示 */
        })
    }
  },

  addToCart(e: WechatMiniprogram.TouchEvent) {
    const itemId = e.currentTarget.dataset.itemId
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      startQuoteCheckout({
        module: 'aigc_package',
        item_id: itemId,
        item_number: 1,
        mode: 'money',
      }).catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
    })
  },

  toUser() {
    wx.navigateTo({ url: '/pages/aigc/user' })
  },

  onShareAppMessage() {
    return { title: wx.getStorageSync('shareTitle') }
  },
})
