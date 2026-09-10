// pages/order/checkout.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'

Page({
  data: {
    contact: '',
    contact_list: [],
    isVisible: false,
  } as Record<string, any>,

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      that.contactList()
    })
  },

  onLoad(options) {
    const that = this

    that.setData({ mode: wx.getStorageSync('product_buy_mode') })

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .post(route('order.checkout'), {
        mode: wx.getStorageSync('product_buy_mode'),
      })
      .then(function (data) {
        that.setData({ title: data.title })

        if (data.cart && data.cart.module == 'vip_package') that.orderSuccess()

        that.setData({
          cart: data.cart,
          shipping_list: data.shipping_list,
          shipping_id: data.shipping_id,
          coupon_list: data.coupon_list,
          coupon_id: data.coupon_id,
          order: data.order,
          amount: data.amount,
          mode: wx.getStorageSync('product_buy_mode'),
        })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })

    if (options.contact_id) {
      that.setData({ contact_id: options.contact_id })
    }

    that.contactSelect()
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  orderSuccess(e?: WechatMiniprogram.CustomEvent) {
    const that = this

    const form = e ? e.detail.value : ''
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('order.checkout.checkout_post'), {
          contact_id: that.data.contact_id || 0,
          contact: form ? form.contact : '',
          address: form ? form.address : '',
          phone: form ? form.phone : '',
          postcode: form ? form.postcode : '',
          action: form ? form.action : '',
          update_user_information: form ? form.update_user_information : '',
          shipping_id: form ? form.shipping_id : 0,
          coupon_id: form ? form.coupon_id : 0,
          mode: wx.getStorageSync('product_buy_mode'),
        })
        .then(function (data) {
          wx.redirectTo({ url: data.cashier_url })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  radioShipping(e: WechatMiniprogram.CustomEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('order.checkout.change_shipping'), {
          shipping_id: e.detail.value,
          coupon_amount: that.data.amount.coupon_amount,
        })
        .then(function (data) {
          that.setData({ amount: data.amount, shipping_id: e.detail.value })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  useCoupon(e: WechatMiniprogram.CustomEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('order.checkout.use_coupon'), {
          coupon_id: e.detail.value,
          shipping_fee: that.data.amount.shipping_fee,
        })
        .then(function (data) {
          that.setData({ amount: data.amount, coupon_id: e.detail.value })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  contactList() {
    const that = this

    http
      .post(route('user.contact.list_json'), {
        contact_id: that.data.contact_id,
      })
      .then(function (data) {
        that.setData({ contact_list: data.contact_list || [] })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  contactSelect(e?: WechatMiniprogram.TouchEvent) {
    const that = this

    let contact_id = e ? e.currentTarget.dataset.contact_id : ''
    if (!contact_id) {
      contact_id = that.data.contact_id || ''
    }

    http
      .post(route('user.contact.info'), {
        contact_id,
      })
      .then(function (data) {
        if (data.contact) {
          that.setData({ contact: data.contact, contact_id: data.contact.id })
          that.contactList()
          if (contact_id) {
            that.setData({ isVisible: false })
          }
        }
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  contactPopup() {
    this.setData({ isVisible: !this.data.isVisible })
  },

  contactInfo() {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('user.contact.info'), {
        })
        .then(function (data) {
          that.setData({ contact: data.contact || {} })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  updateInfo(e: WechatMiniprogram.CustomEvent) {
    this.setData({ update_user_information: e.detail.value })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },

  douRedirectTo(e: WechatMiniprogram.TouchEvent) {
    wx.redirectTo({ url: e.currentTarget.dataset.url })
  },

  douSwitchTab(e: WechatMiniprogram.TouchEvent) {
    wx.switchTab({ url: e.currentTarget.dataset.url })
  },
})
