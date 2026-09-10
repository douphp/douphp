// pages/user/register.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { sendCaptcha } from '../../services/captcha.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'
import { captchaCountdown } from '../../utils/countdown.js'
import { getPromotionUserSn, setPromotionUserSn } from '../../utils/promotion.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    agree: false,
    checkValue: 1,
    showTermCue: false,
    login_mode: 'email',
    mail_username: 0,
    sms_accessKeyId: 0,
    captcha_token: '',
    storage_captcha_token: '',
    promotion_user_sn: '',
    sns_token: '',
    verification_data: null,
    currentAccount: '',
    wrong: {},
    sending: false,
    countdown: 0,
  } as Record<string, any>,

  onLoad(options) {
    const that = this

    this.countdown = captchaCountdown(this)

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    this.setData({ title: pageTitle('site_name') })

    const userSn = (options && options.user_sn) || getPromotionUserSn()
    if (userSn) setPromotionUserSn(userSn)
    that.loadRegisterContext(userSn)
  },

  onShow() {
    cartStore.refresh()
  },

  onUnload() {

    if (this.countdown) this.countdown.stop()
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  registerPost(e: WechatMiniprogram.CustomEvent) {
    const that = this
    const isPhone = this.data.login_mode === 'telphone'
    const form = e.detail.value || {}

    const payload: Record<string, any> = {
      password: form.password,
      password_confirm: form.password_confirm,
      sns_token: that.data.sns_token,
      promotion_user_sn: that.data.promotion_user_sn,
      sns: wx.getStorageSync('sns'),
    }
    if (isPhone) {
      payload.telphone = form.telphone
    } else {
      payload.email = form.email
    }
    if (form.verification) {
      payload.verification = form.verification
      payload.verification_data = JSON.stringify(that.data.verification_data || {})
    }

    http
      .post(route('user.register_post'), payload)
      .then(function (data) {
        if (!data || !data.user) {
          douMsg('注册失败，请重试')
          return
        }
        that.setData({ wrong: {} })

        authStore.login({
          token: data.user.token,
          user_id: data.user.user_id,
        })

        if (data.dou && data.dou.auth && data.dou.auth.is_work) {
          wx.setStorageSync('work', true)
          wx.reLaunch({ url: '/pages/work/work' })
        } else {
          wx.switchTab({ url: '/pages/user/user' })
        }
      })
      .catch(function (err) {
        that.setData({ wrong: err.errors || {} })
        douMsg(err.message || 'request_failed')
      })
  },

  sendVerification() {
    const that = this
    const isPhone = this.data.login_mode === 'telphone'
    const account = this.data.currentAccount

    if (!account) {
      douMsg(
        isPhone
          ? (this.data.lang && this.data.lang.user_telphone_cue) || '请输入手机号'
          : (this.data.lang && this.data.lang.user_email_cue) || '请输入邮箱'
      )
      return
    }
    if (!this.data.captcha_token) {
      this.loadRegisterContext()
      douMsg('请稍候重试')
      return
    }

    this.setData({ sending: true })

    sendCaptcha({
      type: isPhone ? 'sms' : 'email',
      account,
      captcha_token: that.data.captcha_token,
      storage_captcha_token: that.data.storage_captcha_token,
    })
      .then(function (verification) {
        that.setData({ sending: false, verification_data: verification })
        that.countdown.start(60)
        that.loadRegisterContext()
      })
      .catch(function (err) {
        that.setData({ sending: false })
        douMsg(err.message || 'request_failed')
      })
  },

  loadRegisterContext(userSn?: string) {
    const that = this
    const params: Record<string, any> = {}
    if (userSn) params.user_sn = userSn

    http
      .get(route('user.register'), params)
      .then(function (data) {
        that.setData({
          login_mode: data.login_mode || 'email',
          mail_username: data.mail_username ? 1 : 0,
          sms_accessKeyId: data.sms_accessKeyId ? 1 : 0,
          captcha_token: data.captcha_token || '',
          storage_captcha_token: data.storage_captcha_token || '',
          promotion_user_sn: data.promotion_user_sn || '',
          sns_token: data.sns_token || '',
        })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  onAccountInput(e: WechatMiniprogram.CustomEvent) {
    this.setData({ currentAccount: (e.detail.value || '').trim() })
  },

  agreeTerm(e: WechatMiniprogram.CustomEvent) {
    const vals = e.detail.value || []
    if (vals.indexOf('1') >= 0 || vals.indexOf(1) >= 0) {
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
})
