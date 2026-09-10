// pages/user/login_weixin.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'
import { clearPromotionUserSn, getPromotionUserSn, setPromotionUserSn } from '../../utils/promotion.js'

Page({
  data: {
    phoneExist: 'no',
    agree: false,
    checkValue: 1,
    showTermCue: false,
  } as Record<string, any>,

  onLoad(options) {
    const that = this

    that.parsePromotionFromOptions(options)
    that.wxLoginPreload()

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
          that.setData({ phoneExist: 'no' })
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

  parsePromotionFromOptions(options: Record<string, string | undefined> | undefined) {
    if (!options) return
    if (options.user_sn) {
      setPromotionUserSn(options.user_sn)
      return
    }
    if (options.scene) {
      try {
        const scene = decodeURIComponent(options.scene)
        const parts = scene.split('&')
        for (let i = 0; i < parts.length; i++) {
          const kv = parts[i].split('=')
          if (kv.length === 2 && kv[0] === 'user_sn') {
            setPromotionUserSn(kv[1])
            break
          }
        }
      } catch (e) {
        /* scene 解析失败时静默忽略 */
      }
    }
  },

  wxLogin(phone = '') {
    const that = this

    wx.login({
      success(res) {
        if (res.code) {
          http
            .get(route('user.weixin.login'), {
              code: res.code,
              phone,
              promotion_user_sn: getPromotionUserSn(),
            })
            .then(function (data) {
              if (data.sns) {
                wx.setStorageSync('sns', JSON.stringify(data.sns))
                wx.navigateTo({ url: '/pages/user/sns_link' })
              } else if (data.bind) {
                clearPromotionUserSn()
                wx.navigateTo({ url: '/pages/user/sns' })
              } else if (data.user) {
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
              }
            })
            .catch(function (err) {
              douMsg(err.message || 'request_failed')
            })
        }
      },
    })
  },

  wxLoginPreload() {
    const that = this

    wx.login({
      success(res) {
        if (res.code) {
          http
            .get(route('user.weixin.login'), {
              code: res.code,
              act: 'preload',
            })
            .then(function (data) {
              that.setData({ phoneExist: data.phone_exist })
            })
            .catch(function (err) {
              douMsg(err.message || 'request_failed')
            })
        }
      },
    })
  },

  getPhoneNumber(e: WechatMiniprogram.CustomEvent) {
    const that = this

    http
      .get(route('user.weixin.get_phone'), {
        code: e.detail.code,
      })
      .then(function (data) {
        that.wxLogin(data.phone)
      })
      .catch(function (err) {
        wx.showToast({
          title: err.message || 'request_failed',
          duration: 1000,
          icon: 'error',
          mask: true,
        })
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
