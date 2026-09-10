// pages/user/password_reset.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'

Page({
  data: {} as Record<string, any>,

  onLoad() {
    const that = this

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('user.password_reset'))
      .then(function (data) {
        that.setData({ title: data.title })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  onShow() {
    cartStore.refresh()
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  passwordReset(e: WechatMiniprogram.CustomEvent) {
    // 业务文案由后端通过 message 下发（NOT_FOUND/SERVER_ERROR/OK 三种情形）
    http
      .post(route('user.password_reset_post'), {
        email: e.detail.value.email,
      })
      .then(function (data) {
        if ((data as any).__message) {
          douMsg((data as any).__message)
        }
        setTimeout(function () {
          wx.switchTab({ url: '/pages/index/index' })
        }, 3000)
      })
      .catch(function (err) {
        if (err.message) {
          douMsg(err.message)
        }
      })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
