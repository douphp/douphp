// pages/vote/options.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    option_list: [],
    page: 1,
    nomore: false,
  } as Record<string, any>,

  onLoad(options) {
    const that = this

    that.setData({ title: pageTitle('vote_options') })

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    if (options.vote_id) {
      that.setData({ vote_id: options.vote_id })
    }

    that.loadData()
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadData(concat = false, page: number | string = '') {
    const that = this

    http
      .get(route('vote.options'), {
        vote_id: that.data.vote_id,
        page: page ? page : that.data.page,
      })
      .then(function (data) {
        let dataBox
        if (concat) {
          dataBox = that.data.option_list
          const dataNew = data.option_list
          if (dataNew) {
            dataBox = dataBox.concat(dataNew)
          } else {
            that.setData({ nomore: true })
          }
        } else {
          dataBox = data.option_list
        }

        that.setData({
          vote: data.vote,
          option_list: dataBox,
          count_vote: data.count_vote,
          loadpage: false,
        })

        if (data.vote) wx.setStorageSync('shareTitle', data.vote.name)
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  douVote(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const optionId = e.currentTarget.dataset.option_id

    http
      .post(route('vote.poll'), {
        option_id: optionId,
      })
      .then(function (data) {
        const optionList = (that.data.option_list || []).slice()
        for (let i = 0; i < optionList.length; i++) {
          if (parseInt(optionList[i].option_id, 10) === parseInt(optionId, 10)) {
            optionList[i] = Object.assign({}, optionList[i], {
              ip_voted: true,
              poll: data && typeof data.count !== 'undefined' ? data.count : optionList[i].poll,
            })
            break
          }
        }
        that.setData({ option_list: optionList })

        douMsg(data.__message || '')
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  onReachBottom() {
    const that = this
    if (!that.data.nomore) {
      const page = that.data.page + 1
      that.setData({ page, loadpage: true })
      setTimeout(function () {
        that.loadData(true)
      }, 1000)
    }
  },

  onShareAppMessage() {
    return { title: wx.getStorageSync('shareTitle') }
  },
})
