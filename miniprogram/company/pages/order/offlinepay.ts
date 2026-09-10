// pages/order/offlinepay.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'

Page({
  data: {
    payEvidenceTemp: '',
  } as Record<string, any>,

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      that.loadData(that.data.order ? that.data.order.order_sn : 0)
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

  loadData(order_sn: string | number = 0) {
    const that = this

    http
      .get(route('order.cashier.pay'), {
        order_sn,
      })
      .then(function (data) {
        that.setData({ title: data.title, order: data.order })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  payEvidence(e: WechatMiniprogram.TouchEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      wx.chooseImage({
        count: 1,
        sizeType: ['original', 'compressed'],
        sourceType: ['album', 'camera'],
        success(res) {
          that.setData({ payEvidenceTemp: res.tempFilePaths })

          const tempFilePaths = res.tempFilePaths

          wx.uploadFile({
            url: route('order.cashier.pay_evidence'),
            filePath: tempFilePaths[0],
            formData: {
              order_sn: e.currentTarget.dataset.order_sn,
              payment_sn: that.data.order && that.data.order.payment_sn ? that.data.order.payment_sn : '',
            },
            header: {
              Authorization: 'Bearer ' + (wx.getStorageSync('api_token') || ''),
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            name: 'pay_evidence',
            success(info) {
              let raw: any = {}
              try {
                raw = info && info.data ? JSON.parse(info.data) : {}
              } catch (err) {
                raw = {}
              }
              if (raw && raw.code === 'OK') {
                douMsg((raw.message as string) || '上传成功')
                wx.reLaunch({ url: '/pages/order/user' })
              } else {
                douMsg((raw && (raw.message as string)) || 'request_failed')
              }
            },
          })
        },
      })
    })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },

  radioSex(e: WechatMiniprogram.CustomEvent) {
    this.setData({ sex: e.detail.value })
  },
})
