// pages/aftersale/aftersale_apply.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { filebox, fileDel } from '../../services/upload.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {} as Record<string, any>,

  onShow() {
    authStore.ensureLogin()
  },

  onLoad(options) {
    const that = this

    that.setData({ title: pageTitle('aftersale') })

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .get(route('aftersale.user.apply'), {
          order_sn: options.order_sn,
        })
        .then(function (data) {
          that.setData({
            item_list: data.item_list,
            order: data.order,
            img_list: data.img_list || [],
            draft_token: data.draft_token || '',
          })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed', '/pages/order/order_list')
        })
    })
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  checkboxChange(e: WechatMiniprogram.CustomEvent) {
    this.setData({ item_list_data: e.detail.value })
  },

  aftersaleApply(e: WechatMiniprogram.CustomEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('aftersale.user.apply_post'), {
          order_sn: e.detail.value.order_sn,
          reason: e.detail.value.reason,
          item_list: e.detail.value.item_list,
          draft_token: e.detail.value.draft_token || that.data.draft_token || '',
        })
        .then(function () {
          wx.navigateTo({ url: '/pages/aftersale/aftersale' })
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
})
