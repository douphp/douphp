// pages/download_category/download_category.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

const app = getApp<IAppOption>()

Page({
  data: {
    navigationBarAndStatusBarHeight: app.globalData.navigationBarAndStatusBarHeight + 'px',
    download_category: [],
    download_list: [],
    category_id: '',
    page: 1,
    nomore: false,
  } as Record<string, any>,

  onLoad(options) {
    const that = this

    that.setData({ title: pageTitle('download') })

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    if (options.category_id) {
      that.setData({ category_id: options.category_id })
    }

    that.loadData()
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  onShow() {
    cartStore.refresh()
  },

  loadData(concat = false, page: number | string = '') {
    const that = this
    const category_id = that.data.category_id

    http
      .get(route('download'), {
        category_id,
        page: page ? page : that.data.page,
      })
      .then(function (data) {
        wx.setStorageSync('shareTitle', data.title || '')

        let dataBox
        if (concat) {
          dataBox = that.data.download_list
          const dataNew = data.download_list
          if (dataNew) {
            dataBox = dataBox.concat(dataNew)
          } else {
            that.setData({ nomore: true })
          }
        } else {
          dataBox = data.download_list
        }

        that.setData({
          download_list: dataBox,
          download_category: data.download_category,
          cate_info: data.cate_info,
          category_id: data.category_id,
          loadpage: false,
        })

        that.scrollLeft(that.data.currentNav)
      })
      .catch(function (err) {
        that.setData({ loadpage: false })
        douMsg(err.message || 'request_failed')
      })
  },

  douMenu(e: WechatMiniprogram.TouchEvent) {
    this.setData({
      category_id: e.currentTarget.dataset.catid,
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
