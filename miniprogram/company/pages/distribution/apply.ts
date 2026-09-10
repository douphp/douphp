// pages/distribution/apply.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { filebox, fileDel } from '../../services/upload.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {} as Record<string, any>,

  onShow() {
    authStore.ensureLogin()
  },

  onLoad() {
    const that = this

    that.setData({ title: pageTitle('distribution_apply') })

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .get(route('distribution.user.apply'), {
        })
        .then(function (data) {
          that.setData({
            distribution: data.distribution,
            img_list: data.img_list,
            item_id: data.item_id,
          })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed', '/pages/distribution/user')
        })
    })
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  distributionApply(e: WechatMiniprogram.CustomEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('distribution.user.apply_post'), {
          name: e.detail.value.name,
          remark: e.detail.value.remark,
        })
        .then(function () {
          wx.redirectTo({ url: '/pages/distribution/apply' })
        })
        .catch(function (err) {
          const errors = err.errors || {}
          if (errors.toast) {
            douMsg(errors.toast)
          } else {
            that.setData({ errors })
          }
        })
    })
  },

  filebox(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const dataset = e.currentTarget.dataset
    filebox({ dataset } as any, function (img_list) {
      that.setData({ img_list })
    })
  },

  fileDel(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const dataset = e.currentTarget.dataset
    fileDel({ dataset } as any, function (img_list) {
      that.setData({ img_list })
    })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
