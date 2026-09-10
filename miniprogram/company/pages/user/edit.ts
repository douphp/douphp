// pages/user/edit.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'

Page({
  data: {
    avatarTemp: '',
  } as Record<string, any>,

  onShow() {
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      cartStore.refresh()
    })
  },

  onLoad() {
    const that = this

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('user.edit'), {
      })
      .then(function (data) {
        that.setData({ title: data.title, dou: data.dou || { user: {} } })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  userEdit(e: WechatMiniprogram.CustomEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('user.edit_post'), {
          contact: e.detail.value.contact,
          phone: e.detail.value.phone,
          address: e.detail.value.address,
          postcode: e.detail.value.postcode,
          nickname: e.detail.value.nickname,
          sex: e.detail.value.sex,
        })
        .then(function (data) {
          douMsg((data as any).__message || that.data.lang.user_edit_success)

          setTimeout(function () {
            wx.switchTab({ url: '/pages/user/user' })
          }, 2000)
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  uploadAvatar() {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      wx.chooseImage({
        count: 1,
        sizeType: ['original', 'compressed'],
        sourceType: ['album', 'camera'],
        success(res) {
          that.setData({ avatarTemp: res.tempFilePaths })

          const tempFilePaths = res.tempFilePaths

          wx.uploadFile({
            url: route('user.upload_avatar'),
            filePath: tempFilePaths[0],
            formData: {
            },
            header: {
              Authorization: 'Bearer ' + (wx.getStorageSync('api_token') || ''),
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            name: 'avatar',
            success() {},
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
