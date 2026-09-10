// pages/aftersale/work.ts
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
    aftersale_list: [],
    status: 'all',
    page: 1,
    nomore: false,
  } as Record<string, any>,

  onLoad() {
    this.setData({ title: pageTitle('aftersale') })

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })
    // 数据由 onShow 鉴权后加载
  },

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      checkWorkPermission('aftersale')
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
      .get(route('aftersale.work'), {
        status,
        page: page ? page : that.data.page,
      })
      .then(function (data) {
        let dataBox
        if (concat) {
          dataBox = that.data.aftersale_list
          const dataNew = data.aftersale_list
          if (dataNew) {
            dataBox = dataBox.concat(dataNew)
          } else {
            that.setData({ nomore: true })
          }
        } else {
          dataBox = data.aftersale_list
        }

        that.setData({
          aftersale_list: dataBox,
          status_list: data.status_list,
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

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
