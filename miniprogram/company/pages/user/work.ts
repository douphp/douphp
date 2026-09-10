// pages/user/work.ts - 会员工作端列表
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { checkWorkPermission } from '../../services/permission.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

const app = getApp()

Page({
  data: {
    navigationBarAndStatusBarHeight: (app.globalData.navigationBarAndStatusBarHeight || 0) + 'px',
    user_list: [],
    page: 1,
    nomore: false,
  } as Record<string, any>,

  onLoad(options) {
    const that = this

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features', 'user_level_has_data'],
    })

    this.setData({ title: pageTitle('user_list') })

    if (options.status) {
      that.setData({ status: options.status })
    }
    // 数据由 onShow 鉴权后加载
  },

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      checkWorkPermission('user')
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

    http
      .get(route('user.work'), {
        page: page ? page : that.data.page,
      })
      .then(function (data) {
        let dataBox
        if (concat) {
          dataBox = that.data.user_list
          const dataNew = data.user_list
          if (dataNew) {
            dataBox = dataBox.concat(dataNew)
          } else {
            that.setData({ nomore: true })
          }
        } else {
          dataBox = data.user_list
        }

        that.setData({ user_list: dataBox, loadpage: false })
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
