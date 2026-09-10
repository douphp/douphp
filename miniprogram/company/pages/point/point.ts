// pages/point/point.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    b: '',
    s: '',
    product_list: [],
    page: 1,
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

    that.setData({ title: pageTitle('point') })

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

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
      .get(route('point'), {
        b: that.data.b,
        s: that.data.s,
        page: page ? page : that.data.page,
      })
      .then(function (data) {
        let dataBox
        if (concat) {
          dataBox = that.data.product_list
          const dataNew = data.product_list
          if (dataNew) {
            dataBox = dataBox.concat(dataNew)
          } else {
            that.setData({ nomore: true })
          }
        } else {
          dataBox = data.product_list
        }

        that.setData({
          product_list: dataBox,
          sort_list: data.sort_list,
          loadpage: false,
        })
      })
      .catch(function (err) {
        that.setData({ loadpage: false })
        douMsg(err.message || 'request_failed')
      })
  },

  douSort(e: WechatMiniprogram.TouchEvent) {
    this.setData({
      b: e.currentTarget.dataset.b,
      s: e.currentTarget.dataset.s,
      page: 1,
      nomore: false,
    })
    this.loadData(false, 1)
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
