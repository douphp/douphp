// pages/order/show.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { checkWorkPermission } from '../../services/permission.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'

Page({
  data: {} as Record<string, any>,

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      cartStore.refresh()
      that.loadData()
    })
  },

  onLoad() {
    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })
    // 数据加载由 onShow 鉴权后触发
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadData(order_sn: string | number = 0) {
    const that = this

    http
      .get(route('order.work.show', { order_sn }))
      .then(function (data) {
        that.setData({
          title: data.title,
          order: data.order,
          payment: data.payment,
          need_pay_check: data.need_pay_check || false,
          shipping_list: data.shipping_list || [],
          shipping_index: 0,
          tracking_no: data.order ? data.order.tracking_no : '',
        })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  bindShippingChange(e: WechatMiniprogram.CustomEvent) {
    this.setData({ shipping_index: e.detail.value })
  },

  trackingNoInput(e: WechatMiniprogram.CustomEvent) {
    this.setData({ tracking_no: e.detail.value })
  },

  payCheck(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const order_id = e.currentTarget.dataset.order_id

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      checkWorkPermission('order')
      wx.showModal({
        title: that.data.lang.order_pay_check,
        content: that.data.lang.order_pay_check_confirm || '确认已收到款项？',
        success(res) {
          if (!res.confirm) return
          http
            .post(route('order.work.pay_check'), {
              order_id,
            })
            .then(function () {
              that.loadData(that.data.order.order_sn)
            })
            .catch(function (err) {
              douMsg(err.message || 'request_failed')
            })
        },
      })
    })
  },

  trackingSubmit(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const order_id = e.currentTarget.dataset.order_id
    const shipping_list = that.data.shipping_list
    const shipping_index = that.data.shipping_index
    const shipping_id = shipping_list[shipping_index] ? shipping_list[shipping_index].slug : ''
    const tracking_no = that.data.tracking_no || ''

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      checkWorkPermission('order')
      if (!tracking_no) {
        wx.showToast({
          title: that.data.lang.order_tracking_no + that.data.lang.is_empty,
          icon: 'none',
        })
        return
      }
      http
        .post(route('order.work.tracking'), {
          order_id,
          shipping_id,
          tracking_no,
        })
        .then(function () {
          wx.showToast({
            title: that.data.lang.order_tracking_submit_success || '提交成功',
            icon: 'success',
          })
          that.loadData(that.data.order.order_sn)
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  previewPayEvidence(e: WechatMiniprogram.TouchEvent) {
    wx.previewImage({
      urls: [e.currentTarget.dataset.src],
      current: e.currentTarget.dataset.src,
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

  wxpay(e: WechatMiniprogram.TouchEvent) {
    const that = this

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
              douMsg('支付成功')
              setTimeout(function () {
                that.loadData(e.currentTarget.dataset.order_sn)
              }, 1000)
            },
            fail() {
              douMsg('支付失败')
            },
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
})
