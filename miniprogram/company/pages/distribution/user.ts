// pages/distribution/user.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    page: 1,
    user_sn: '',
    nomore: false,
  } as Record<string, any>,

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      that.setData({ page: 1, nomore: false })
      that.loadData(false, 1)
    })
  },

  onLoad(options) {
    const that = this

    that.setData({ title: pageTitle('distribution_user') })
    wx.setStorageSync('shareTitle', pageTitle('distribution_user'))

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    if (options.user_sn) {
      that.setData({ user_sn: options.user_sn })
    }
    // 数据由 onShow 鉴权后加载
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadData(concat = false, page: number | string = '') {
    const that = this

    http
      .get(route('distribution.user'), {
        user_sn: that.data.user_sn,
        page: page ? page : that.data.page,
      })
      .then(function (data) {
        if (data && data.redirect) {
          wx.redirectTo({ url: data.redirect })
          return
        }

        let dataBox
        if (concat) {
          dataBox = that.data.money_list
          const dataNew = data.money_list
          if (dataNew) {
            dataBox = dataBox.concat(dataNew)
          } else {
            that.setData({ nomore: true })
          }
        } else {
          dataBox = data.money_list
        }

        that.setData({ money_list: dataBox, loadpage: false })
      })
      .catch(function (err) {
        that.setData({ loadpage: false })
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

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
