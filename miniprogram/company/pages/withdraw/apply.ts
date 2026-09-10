// pages/withdraw/apply.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {} as Record<string, any>,

  onShow() {
    authStore.ensureLogin()
  },

  onLoad() {
    const that = this

    that.setData({ title: pageTitle('withdraw_apply') })

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .get(route('withdraw.user.apply'), {
        })
        .then(function (data) {
          that.setData({ withdraw: data.withdraw, money_total: data.money_total })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed', '/pages/withdraw/user')
        })
    })
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  withdrawApply(e: WechatMiniprogram.CustomEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('withdraw.user.apply_post'), {
          post: JSON.stringify(e.detail.value),
        })
        .then(function (data) {
          douMsg(data.__message || that.data.lang.withdraw_apply_success, '/pages/withdraw/user')
        })
        .catch(function (err) {
          that.setData({ wrong: err.errors || {} })
        })
    })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
