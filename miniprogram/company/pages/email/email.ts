// pages/email/email.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    title: '',
    email: '',
    wrong: {},
  } as Record<string, any>,

  onLoad() {
    const that = this
    that.setData({ title: pageTitle('email') || '邮件订阅' })

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

  onShow() {
    authStore.ensureLogin()
  },

  submit(e: WechatMiniprogram.CustomEvent) {
    const that = this
    const email = (e.detail.value.email || '').trim()

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return

      http
        .post(route('email.store'), {
          email,
        })
        .then(function (data) {
          that.setData({ email: '', wrong: {} })
          douMsg(data.__message || (that.data.lang && that.data.lang.email_subscribe_success) || '订阅成功')
        })
        .catch(function (err) {
          that.setData({ wrong: err.errors || {} })
          douMsg(err.message || (that.data.lang && that.data.lang.email_cue) || '邮箱格式不正确')
        })
    })
  },

  onShareAppMessage() {
    return { title: wx.getStorageSync('shareTitle') || this.data.title }
  },
})
