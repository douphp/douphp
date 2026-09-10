// pages/money/money.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'

const grcode: any = require('../../utils/grcode/init.js')

Page({
  data: {
    pay_code_mode: false,
    number: '',
    bgclass: '',
  } as Record<string, any>,

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      that.grCode()
    })
  },

  onLoad() {
    const that = this

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('money'), {
      })
      .then(function (data) {
        that.setData({ title: data.title })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })

    this.grTimer = setInterval(function () {
      that.grCode()
    }, 60000)
  },

  onUnload() {
    if (this.grTimer) {
      clearInterval(this.grTimer)
    }
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  grCode() {
    const that = this

    that.setData({ bgclass: 'light' })

    http
      .get(route('money.user.pay_code'), {
      })
      .then(function (data) {
        grcode.barcode('barcode', data.pay_code, 680, 200)
        grcode.qrcode('qrcode', data.pay_code, 420, 420)

        that.setData({
          pay_code: data.pay_code,
          user: data.user,
          total: data.total,
          bgclass: '',
        })
      })
      .catch(function (err) {
        that.setData({ bgclass: '' })
        douMsg(err.message || 'request_failed')
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
