// pages/form/show.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'

Page({
  data: {
    post_data: [],
    pickerIndexes: {},
    success: false,
  } as Record<string, any>,

  onLoad(options) {
    const that = this

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('form.show', { id: options.id }), {
        post_data: that.data.post_data,
      })
      .then(function (data) {
        const initIndexes: Record<string, number> = {}
        data.form.elements_list.forEach(function (elements: any) {
          if (elements.type == 'select') {
            initIndexes[elements.slug] = 0
          }
        })

        that.setData({
          title: data.form.name,
          form: data.form,
          post_data: data.post_data,
          pickerIndexes: initIndexes,
        })

        wx.setStorageSync('shareTitle', data.form.name)
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

  formSubmit(e: WechatMiniprogram.CustomEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('form.submit'), {
          post: JSON.stringify(e.detail.value),
          post_data: JSON.stringify(that.data.post_data),
        })
        .then(function (data) {
          if (data.back_url) {
            setTimeout(function () {
              wx.navigateTo({ url: data.back_url })
            }, 2000)
          } else {
            douMsg(that.data.lang.consultation_insert_success)
            that.setData({ success: true })
          }
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  selectChange(e: WechatMiniprogram.CustomEvent) {
    const name = e.currentTarget.dataset.name
    this.setData({
      ['pickerIndexes.' + name]: e.detail.value,
      ['post_data.' + name]: e.currentTarget.dataset.option[e.detail.value],
    })
  },

  radioChange(e: WechatMiniprogram.CustomEvent) {
    const name = e.currentTarget.dataset.name
    this.setData({
      ['post_data.' + name]: e.detail.value,
    })
  },

  onShareAppMessage() {
    return { title: wx.getStorageSync('shareTitle') }
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },

  douSwitchTab(e: WechatMiniprogram.TouchEvent) {
    wx.switchTab({ url: e.currentTarget.dataset.url })
  },
})
