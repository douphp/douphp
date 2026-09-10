// pages/sn/sn.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {} as Record<string, any>,

  onLoad() {
    const that = this

    that.setData({ title: pageTitle('sn') })
    wx.setStorageSync('shareTitle', pageTitle('sn'))

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

  douSearch(e: WechatMiniprogram.CustomEvent) {
    const that = this

    http
      .get(route('sn.search'), {
        code: e.detail.value.code,
      })
      .then(function (data) {
        that.setData({ result: data.result, code: data.code })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  douClear() {
    this.setData({ result: [], code: '' })
  },
})
