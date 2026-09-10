// pages/share/apply.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { filebox, fileDel } from '../../services/upload.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    draft_token: '',
    img_list: [],
  } as Record<string, any>,

  onShow() {
    authStore.ensureLogin()
  },

  onLoad() {
    const that = this

    that.setData({ title: pageTitle('share') })

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .get(route('share.user.apply'), {
        })
        .then(function (data) {
          that.setData({
            draft_token: data.draft_token || '',
            img_list: data.img_list_html || [],
          })
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

  applyPost() {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('share.user.apply_post'), {
          draft_token: that.data.draft_token,
        })
        .then(function (data) {
          douMsg(data.__message || that.data.lang.share_apply_success, '/pages/share/user')
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  filebox(e: WechatMiniprogram.TouchEvent) {
    const that = this
    filebox({ dataset: e.currentTarget.dataset } as any, function (img_list) {
      that.setData({ img_list })
    })
  },

  fileDel(e: WechatMiniprogram.TouchEvent) {
    const that = this
    fileDel({ dataset: e.currentTarget.dataset } as any, function (img_list) {
      that.setData({ img_list })
    })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },

  radioSex(e: WechatMiniprogram.CustomEvent) {
    this.setData({ sex: e.detail.value })
  },
})
