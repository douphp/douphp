// pages/user/sns.ts
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
      that.loadData()
    })

    cartStore.refresh()
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadData() {
    const that = this

    http
      .post(route('user.sns'), {
      })
      .then(function (data) {
        that.setData({ title: data.title, plugin_list: data.plugin_list })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  bindWeixin(e: WechatMiniprogram.CustomEvent) {
    const that = this

    if (e.detail.userInfo) {
      that.wxLogin()
    } else {
      wx.showModal({
        title: '警告',
        content: '您点击了拒绝授权，将无法进入小程序，请授权之后再进入!!!',
        showCancel: false,
        confirmText: '返回授权',
        success(res) {
          if (res.confirm) {
            console.log('用户点击了“返回授权”')
          }
        },
      })
    }
  },

  wxLogin() {
    const that = this

    wx.getSetting({
      success(res) {
        if (res.authSetting['scope.userInfo']) {
          wx.getUserInfo({
            success() {
              wx.login({
                success(loginRes) {
                  const code = loginRes.code
                  wx.getUserInfo({
                    success(data) {
                      const rawData = data.rawData
                      const signature = data.signature
                      const encryptedData = data.encryptedData
                      const iv = data.iv
                      http
                        .get(route('user.weixin.login'), {
                          code,
                          rawData,
                          signature,
                          iv,
                          encryptedData,
                        })
                        .then(function (info) {
                          if (info.bind) {
                            douMsg('绑定成功')
                            that.loadData()
                          }
                        })
                        .catch(function (err) {
                          douMsg(err.message || 'request_failed')
                        })
                    },
                  })
                },
              })
            },
          })
        }
      },
    })
  },

  bindRemove(e: WechatMiniprogram.TouchEvent) {
    const that = this

    http
      .post(route('user.sns'), {
        remove: e.currentTarget.dataset.remove,
      })
      .then(function (data) {
        if (data.remove) {
          douMsg('成功解除绑定')
          that.setData({ plugin_list: data.plugin_list })
        }
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  douCue(e: WechatMiniprogram.TouchEvent) {
    douMsg(e.currentTarget.dataset.msg)
  },
})
