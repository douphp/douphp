// pages/money/recharge.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'

Page({
  data: {
    package_list: [],
    current_id: '',
  } as Record<string, any>,

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      that.loadData()
    })
  },

  onLoad() {
    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })
    // 数据由 onShow 鉴权后加载
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadData() {
    const that = this
    const current_id = that.data.current_id || ''

    http
      .get(route('money.recharge'), {
        current_id,
      })
      .then(function (data) {
        that.setData({
          package_list: data.package_list,
          current_id: data.current_id,
          current: data.current,
          total: data.total,
          title: data.title,
        })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  douSelect(e: WechatMiniprogram.TouchEvent) {
    this.setData({ current_id: e.currentTarget.dataset.current_id })
    this.loadData()
  },

  addToCart(e: WechatMiniprogram.TouchEvent) {
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('order.cart.store'), {
          module: e.currentTarget.dataset.module,
          item_id: e.currentTarget.dataset.item_id,
        })
        .then(function (data) {
          wx.navigateTo({
            url: data.mode == 'point' ? '/pages/order/checkout?mode=point' : '/pages/order/checkout',
          })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  douRedirectTo(e: WechatMiniprogram.TouchEvent) {
    wx.redirectTo({ url: e.currentTarget.dataset.url })
  },

  douNavigateBack(e: WechatMiniprogram.TouchEvent) {
    const delta = e.currentTarget.dataset.delta
    wx.navigateBack({ delta: delta ? delta : 1 })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
