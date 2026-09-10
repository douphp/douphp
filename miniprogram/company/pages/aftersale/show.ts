// pages/aftersale/show.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
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

    that.setData({ title: pageTitle('aftersale_show') })

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .get(route('aftersale.work.show', { id: options.id }))
        .then(function (data) {
          that.setData({ aftersale: data.aftersale })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed', '/pages/aftersale/work')
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
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('aftersale.work.handle'), {
          id: e.detail.value.id,
          aftersale_money: e.detail.value.aftersale_money,
          handle_record: e.detail.value.handle_record,
        })
        .then(function () {
          wx.navigateTo({ url: '/pages/aftersale/work' })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
