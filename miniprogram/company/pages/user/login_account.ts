// pages/user/login_account.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    showGetPhone: false,
    agree: false,
    checkValue: 1,
    showTermCue: false,
  } as Record<string, any>,

  onLoad() {
    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    this.setData({ title: pageTitle('site_name') })
  },

  onShow() {
    const that = this

    http
      .post(route('user.check_login_state'))
      .then(function (data) {
        if (data.phone == 'no') {
          that.setData({ noPhone: true })
        }
      })
      .catch(function () {
        /* 未登录态访问该接口属正常，无需提示 */
      })

    cartStore.refresh()
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  login(e: WechatMiniprogram.CustomEvent) {
    const that = this

    http
      .post(route('user.login_post'), {
        username: e.detail.value.username,
        password: e.detail.value.password,
        sns: wx.getStorageSync('sns'),
      })
      .then(function (data) {
        that.setData({ errorMsg: '', wrong: {} })

        authStore.login({
          token: data.user.token,
          user_id: data.user.user_id,
        })

        if (data.dou && data.dou.auth && data.dou.auth.is_work) {
          wx.setStorageSync('work', true)
          wx.reLaunch({ url: '/pages/work/work' })
        } else {
          wx.reLaunch({ url: '/pages/user/user' })
        }
      })
      .catch(function (err) {
        that.setData({ errorMsg: err.message || '', wrong: err.errors || {} })
      })
  },

  agreeTerm(e: WechatMiniprogram.CustomEvent) {
    if (e.detail.value.includes('1')) {
      this.setData({ agree: true, showTermCue: false })
    } else {
      this.setData({ agree: false })
    }
  },

  termCue() {
    this.setData({ showTermCue: true })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },

  douSwitchTab(e: WechatMiniprogram.TouchEvent) {
    wx.switchTab({ url: e.currentTarget.dataset.url })
  },
})
