// pages/product_category/product_category.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

const app = getApp<IAppOption>()

Page({
  data: {
    navigationBarAndStatusBarHeight: ((app.globalData.navigationBarAndStatusBarHeight || 0) + 65) + 'px',
    product_category: [],
    product_list: [],
    category_id: '',
    brand_id: '',
    by: '',
    sort: '',
    page: 1,
    nomore: false,
    treeHeight: 500,
  } as Record<string, any>,

  onLoad(options) {
    const that = this

    that.setData({ title: pageTitle('product') })

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    if (options.category_id) {
      that.setData({ category_id: options.category_id })
    }
    if (options.brand_id) {
      that.setData({ brand_id: options.brand_id })
    }
    that.getTreeHeight()
    that.loadData()
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  onShow() {
    const that = this

    if (wx.getStorageSync('product_category_id')) {
      that.setData({ category_id: wx.getStorageSync('product_category_id') })
      that.loadData()
    }

    cartStore.refresh()
  },

  loadData(concat = false, page: number | string = '') {
    const that = this
    const category_id = that.data.category_id
    const brand_id = that.data.brand_id

    http
      .get(route('product'), {
        category_id,
        brand_id,
        by: that.data.by,
        sort: that.data.sort,
        page: page ? page : that.data.page,
      })
      .then(function (data) {
        wx.setStorageSync('shareTitle', data.title || '')

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
          product_category: data.product_category,
          sort_list: data.sort_list,
          cate_info: data.cate_info,
          category_id: data.category_id,
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
      by: e.currentTarget.dataset.by,
      sort: e.currentTarget.dataset.sort,
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

  douMenu(e: WechatMiniprogram.TouchEvent) {
    wx.removeStorage({ key: 'product_category_id' })
    this.setData({
      category_id: e.currentTarget.dataset.catid,
      page: 1,
      nomore: false,
    })
    this.loadData()
  },

  getTreeHeight() {
    const that = this
    const windowHeight = wx.getWindowInfo().windowHeight
    const query = wx.createSelectorQuery()

    query
      .select('.page-top')
      .boundingClientRect(function (rect: any) {
        that.setData({ treeHeight: windowHeight - rect.height })
      })
      .exec()
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
