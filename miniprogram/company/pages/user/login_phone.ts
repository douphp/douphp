// pages/user/login_phone.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { sendCaptcha } from '../../services/captcha.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'
import { captchaCountdown } from '../../utils/countdown.js'
import { getPromotionUserSn } from '../../utils/promotion.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    rec: 'default',
    agree: false,
    checkValue: 1,
    showTermCue: false,
    codeNumber: 4,
    isFocus: true,
    vcode: '',
    ispassword: false,
    time: 0,
    telphone: '',
    wrong: false,
    verification_data: null,
    captcha_token: '',
    storage_captcha_token: '',
  } as Record<string, any>,

  onLoad(options) {
    const that = this
    this.countdown = captchaCountdown(this, { field: 'time', seconds: 60 })

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    this.setData({ title: pageTitle('site_name') })

    if (options.telphone) {
      that.setData({ telphone: options.telphone })
    }
  },

  onUnload() {

    if (this.countdown) this.countdown.stop()
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  onShow() {
    const that = this

    http
      .post(route('user.check_login_state'))
      .then(function (data) {
        if (data && data.phone == 'no') that.setData({ noPhone: true })
      })
      .catch(function () {})

    cartStore.refresh()

    that.loadCaptchaToken()
  },

  loadCaptchaToken() {
    const that = this
    http
      .get(route('captcha.token'))
      .then(function (data) {
        that.setData({
          captcha_token: data.captcha_token || '',
          storage_captcha_token: data.storage_captcha_token || '',
        })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  loginPhonePost(e: WechatMiniprogram.CustomEvent) {
    const that = this
    that.setData({ vcode: e.detail.value })

    if (that.data.vcode.length !== 4) return

    const verificationData = that.data.verification_data || {}

    http
      .post(route('user.login_phone_post'), {
        telphone: verificationData.account || that.data.telphone,
        verification: e.detail.value,
        verification_data: JSON.stringify(verificationData),
        promotion_user_sn: getPromotionUserSn(),
      })
      .then(function (data) {
        if (!data || !data.user) {
          douMsg('登录失败')
          return
        }

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
        that.setData({ wrong: true })
        douMsg(err.message || 'request_failed')
      })
  },

  sendSms(e: WechatMiniprogram.CustomEvent) {
    const that = this
    const telphone = e.detail.value.telphone

    if (!telphone) {
      douMsg((that.data.lang && that.data.lang.user_telphone_cue) || '请输入手机号')
      return
    }
    if (!that.data.captcha_token) {
      douMsg('请稍候重试')
      return
    }

    sendCaptcha({
      type: 'sms',
      account: telphone,
      captcha_token: that.data.captcha_token,
      storage_captcha_token: that.data.storage_captcha_token,
      check: '',
    })
      .then(function (verification) {
        that.setData({
          rec: 'code',
          telphone: verification.account || telphone,
          verification_data: verification,
          wrong: false,
          vcode: '',
        })
        if (that.countdown) that.countdown.start(60)
        that.loadCaptchaToken()
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  sendAgain() {
    if (this.countdown) this.countdown.stop()
    this.setData({
      rec: 'default',
      time: 0,
      wrong: false,
      vcode: '',
      verification_data: null,
    })
    this.loadCaptchaToken()
  },

  codeFocus() {
    this.setData({ isFocus: true })
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
