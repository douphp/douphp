// pages/vote/option.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    vote: {},
    vote_option: {},
  } as Record<string, any>,

  onLoad(options) {
    const that = this

    that.setData({ title: pageTitle('vote_option') })

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('vote.option'), {
        vote_id: options.vote_id,
        option_id: options.option_id,
      })
      .then(function (data) {
        that.setData({
          vote: data.vote || {},
          vote_option: data.vote_option || {},
        })

        if (data.vote_option && data.vote_option.name) {
          wx.setStorageSync('shareTitle', data.vote_option.name)
        }
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

  douVote(e: WechatMiniprogram.TouchEvent) {
    const that = this

    http
      .post(route('vote.poll'), {
        option_id: e.currentTarget.dataset.option_id,
      })
      .then(function (data) {
        const voteOption = that.data.vote_option || {}
        voteOption.ip_voted = true
        if (data && typeof data.count !== 'undefined') {
          voteOption.poll = data.count
        }
        that.setData({ vote_option: voteOption })
        douMsg(data.__message || '')
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  onShareAppMessage() {
    return { title: wx.getStorageSync('shareTitle') }
  },
})
