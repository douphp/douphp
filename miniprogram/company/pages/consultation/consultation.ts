// pages/consultation/consultation.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    textAreaMaxLen: 200,
    inputValueLength: 0,
  } as Record<string, any>,

  onShow() {
    authStore.ensureLogin()
  },

  onLoad() {
    const that = this

    that.setData({ title: pageTitle('consultation') })

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  consultationAdd(e: WechatMiniprogram.CustomEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('consultation.store'), {
          post: JSON.stringify(e.detail.value),
        })
        .then(function (data) {
          douMsg(data.__message || that.data.lang.consultation_insert_success)
          setTimeout(function () {
            wx.switchTab({ url: '/pages/index/index' })
          }, 2000)
        })
        .catch(function (err) {
          douMsg(err.message || that.data.lang.consultation_phone + that.data.lang.is_wrong)
        })
    })
  },

  bindKeyInput(e: WechatMiniprogram.CustomEvent) {
    if (e.detail.value.length > 200) {
      this.setData({ inputValueLength: 200, inputValue: e.detail.value })
    } else {
      this.setData({ inputValueLength: e.detail.value.length, inputValue: e.detail.value })
    }
  },

  bindPickerChange(e: WechatMiniprogram.PickerChange) {
    this.setData({ index: e.detail.value })
  },

  onShareAppMessage() {
    return { title: wx.getStorageSync('shareTitle') }
  },
})
