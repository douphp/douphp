// pages/money/work.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'

Page({
  data: {
    pay_code_mode: false,
    number: '',
  } as Record<string, any>,

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .get(route('money.work'), {
        })
        .then(function (data) {
          that.setData({ title: data.title, pay_code_mode: data.pay_code_mode })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed', 'index')
        })
    })
  },

  onLoad() {
    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })
    // 业务数据由 onShow 鉴权后加载
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  searchUser(e: WechatMiniprogram.CustomEvent) {
    wx.navigateTo({ url: '/pages/money/work_desk?number=' + e.detail.value.telphone })
  },

  scanCode() {
    const that = this

    if (that.data.pay_code_mode) {
      wx.scanCode({
        scanType: ['barCode', 'qrCode'],
        success(res) {
          if (!res.result) {
            wx.showToast({ title: '二维码无效', icon: 'none' })
            return
          }
          wx.navigateTo({ url: '/pages/money/work_desk?number=' + res.result })
        },
        fail(err) {
          if (err.errMsg && err.errMsg.indexOf('cancel') > -1) {
            return
          }
          wx.showToast({ title: err.errMsg || '扫码失败', icon: 'none', duration: 3000 })
        },
      })
    }
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },

  catSwitchTab(e: WechatMiniprogram.TouchEvent) {
    wx.switchTab({ url: e.currentTarget.dataset.url })
  },
})
