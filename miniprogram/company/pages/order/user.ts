// pages/order/user.ts - 订单列表（会员中心）
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

const app = getApp()

Page({
  data: {
    navigationBarAndStatusBarHeight: (app.globalData.navigationBarAndStatusBarHeight || 0) + 'px',
    order_list: [],
    status: 'all',
    page: 1,
    nomore: false,
  } as Record<string, any>,

  onLoad(options) {
    const that = this

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    this.setData({ title: pageTitle('order_list') })

    if (options && options.status !== undefined && options.status !== null && options.status !== '') {
      that.setData({ status: options.status })
    }
    // 首次进入由 onShow 鉴权后加载
  },

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      cartStore.refresh()
      that.setData({ page: 1, nomore: false })
      that.loadData(false, 1)
    })
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadData(concat = false, page: number | string = '') {
    const that = this
    const status = that.data.status

    http
      .get(route('order.user'), {
        status,
        page: page ? page : that.data.page,
      })
      .then(function (data) {
        let dataBox
        if (concat) {
          dataBox = that.data.order_list
          const dataNew = data.order_list
          if (dataNew) {
            dataBox = dataBox.concat(dataNew)
          } else {
            that.setData({ nomore: true })
          }
        } else {
          dataBox = data.order_list
        }

        that.setData({
          order_list: dataBox,
          status_list: data.status_list,
          payment: data.payment,
          loadpage: false,
        })

        that.scrollLeft(that.data.currentNav)
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

  douMenu(e: WechatMiniprogram.TouchEvent) {
    this.setData({
      status: e.currentTarget.dataset.status,
      page: 1,
      nomore: false,
      currentNav: e.currentTarget.dataset.index,
    })
    this.loadData()
  },

  scrollLeft(currentNav = 1) {
    const that = this

    const query = wx.createSelectorQuery()
    query.selectAll('.navScroll .item').boundingClientRect()
    query.exec(function (res: any) {
      let num = 0
      for (let i = 0; i < currentNav; i++) {
        num += res[0][i].width
      }
      that.setData({ scrollLeft: Math.ceil(num) })
    })
  },

  orderCancel(e: WechatMiniprogram.TouchEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('order.user.cancel'), {
          order_sn: e.currentTarget.dataset.order_sn,
        })
        .then(function () {
          that.loadData()
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
