// pages/vote/show.ts
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

    that.setData({ title: pageTitle('vote_show') })

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('vote.show', { id: options.id }))
      .then(function (data) {
        that.setData({ vote: data.vote })

        if (data.vote) wx.setStorageSync('shareTitle', data.vote.name)
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
