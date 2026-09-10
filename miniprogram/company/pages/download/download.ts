// pages/download/download.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {} as Record<string, any>,

  onLoad(options) {
    const that = this

    that.setData({ title: pageTitle('download_detail') })

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('download.show', { id: options.id }))
      .then(function (data) {
        that.setData({ download: data.download, defined: data.defined })
        wx.setStorageSync('shareTitle', data.download ? data.download.title : '')
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

  download(e: WechatMiniprogram.TouchEvent) {
    wx.downloadFile({
      url: e.currentTarget.dataset.link,
      success(res) {
        wx.openDocument({
          filePath: res.tempFilePath,
          showMenu: true,
        })
      },
      fail() {
        /* 下载失败静默 */
      },
    })
  },

  onShareAppMessage() {
    return { title: wx.getStorageSync('shareTitle') }
  },
})
