// pages/article/article.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    article: {},
    defined: [],
  } as Record<string, any>,

  onLoad(options) {
    const that = this
    const articleId = options.id || options.item_id || options.article_id || ''

    that.setData({ title: pageTitle('article_detail') })

    showShareMenu()

    // site/lang 改由 commonStore 自动绑定
    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('article.show', { id: articleId }))
      .then(function (data) {
        that.setData({
          article: data.article || {},
          defined: data.defined || [],
        })
        wx.setStorageSync('shareTitle', data.article ? data.article.title : '')
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
