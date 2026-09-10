// pages/user/password.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'

Page({
  data: {} as Record<string, any>,

  onLoad() {
    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })
  },

  onShow() {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      cartStore.refresh()

      http
        .get(route('user.password'), {
        })
        .then(function (data) {
          that.setData({ title: data.title })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  passwordPost(e: WechatMiniprogram.CustomEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('user.password_post'), {
          old_password: e.detail.value.old_password,
          password: e.detail.value.password,
          password_confirm: e.detail.value.password_confirm,
        })
        .then(function (data) {
          douMsg((data as any).__message || that.data.lang.user_password_success)

          // 密码修改后 shell 已失效，清除登录态并强制重新登录
          setTimeout(function () {
            authStore.logout()
            wx.reLaunch({ url: '/pages/user/login_weixin' })
          }, 2000)
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },
})
