// pages/health/work.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { checkWorkPermission } from '../../services/permission.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

const app = getApp<IAppOption>()

Page({
  data: {
    navigationBarAndStatusBarHeight: (app.globalData.navigationBarAndStatusBarHeight || 0) + 'px',
    health_list: [],
    status: '',
    page: 1,
    nomore: false,
  } as Record<string, any>,

  onLoad() {
    this.setData({ title: pageTitle('health') || '健康' })

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })
  },

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      checkWorkPermission('health')
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

    http
      .get(route('health.work'), {
        status: that.data.status,
        page: page ? page : that.data.page,
      })
      .then(function (data) {
        let list
        if (concat) {
          list = that.data.health_list || []
          if (data.health_list && data.health_list.length) {
            list = list.concat(data.health_list)
          } else {
            that.setData({ nomore: true })
          }
        } else {
          list = data.health_list || []
        }
        that.setData({
          health_list: list,
          status_list: data.status_list,
          loadpage: false,
        })
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
    })
    this.loadData()
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
