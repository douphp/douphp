// pages/item/item.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    item: {},
    defined: [],
    access: false,
    paid_use: false,
  } as Record<string, any>,

  onLoad(options) {
    const that = this
    const itemId = options.id || options.item_id || ''

    that.setData({ title: pageTitle('item_detail') })

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('item.show', { id: itemId }))
      .then(function (data) {
        that.setData({
          item: data.item || {},
          defined: data.defined || [],
          access: data.access || false,
          paid_use: data.paid_use || false,
        })
        wx.setStorageSync('shareTitle', data.item ? data.item.title : '')
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

  videoErrorCallback(e: WechatMiniprogram.CustomEvent) {
    console.log('视频错误信息:')
    console.log(e.detail.errMsg)
  },

  onShareAppMessage() {
    return { title: wx.getStorageSync('shareTitle') }
  },
})
