// pages/page/page.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'

Page({
  data: {} as Record<string, any>,

  onLoad(options) {
    const that = this

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('page'), { id: options.id })
      .then(function (data) {
        const page = data.page || {}
        that.setData({ title: page.name, page, defined: data.defined })
        wx.setStorageSync('shareTitle', page.name || '')
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

  onShareAppMessage() {
    return { title: wx.getStorageSync('shareTitle') }
  },
})
