// pages/order/user/show.ts - 订单详情（会员中心）
import { createStoreBindings } from '../../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, cartStore, commonStore } from '../../../stores/index.js'
import { http } from '../../../services/http.js'
import { route } from '../../../utils/route.js'
import { douMsg } from '../../../utils/ui.js'

Page({
  data: {
    order_sn: '',
  } as Record<string, any>,

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      cartStore.refresh()
      that.loadData(that.data.order_sn)
    })
  },

  onLoad(options) {
    const that = this
    const orderSn = options && options.order_sn ? String(options.order_sn) : ''

    that.setData({ order_sn: orderSn })

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })
    // 首次进入由 onShow 鉴权后加载
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadData(order_sn: string | number = '') {
    const that = this
    const orderSn = order_sn === undefined || order_sn === null ? '' : String(order_sn)

    http
      .get(route('order.user.show', { order_sn: orderSn }))
      .then(function (data) {
        that.setData({ title: data.title, order: data.order, payment: data.payment })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  orderCancel(e: WechatMiniprogram.TouchEvent) {
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('order.user.cancel'), {
          order_sn: e.currentTarget.dataset.order_sn,
        })
        .then(function () {
          douMsg('订单取消成功', '/pages/order/user')
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
