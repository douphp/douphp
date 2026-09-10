// pages/order/cashier.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'

Page({
  data: {
    payment: 'wxpay',
  } as Record<string, any>,

  onShow() {
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
    })
  },

  onLoad(options) {
    const that = this

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .post(route('order.cashier'), {
        order_sn: options.order_sn,
      })
      .then(function (data) {
        that.setData({ title: data.title, order: data.order })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  selectPayment() {
    if (this.data.payment == 'wxpay') {
      this.setData({ payment: 'offlinepay' })
    } else {
      this.setData({ payment: 'wxpay' })
    }
  },

  wxpay(e: WechatMiniprogram.TouchEvent) {
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('user.weixin.pay'), {
          order_sn: e.currentTarget.dataset.order_sn,
        })
        .then(function (data) {
          wx.requestPayment({
            timeStamp: data.timeStamp,
            nonceStr: data.nonceStr,
            package: data.package,
            signType: 'MD5',
            paySign: data.paySign,
            success() {
              douMsg('支付成功', '/pages/order/user')
            },
            fail() {},
          })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },

  douSwitchTab(e: WechatMiniprogram.TouchEvent) {
    wx.switchTab({ url: e.currentTarget.dataset.url })
  },
})
