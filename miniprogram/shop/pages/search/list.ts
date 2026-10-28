// pages/search/list.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    keyword: '',
    category_id: '',
    by: '',
    sort: '',
    search_list: [],
    sort_list: [],
    page: 1,
    nomore: false,
    loading: false,
    loadpage: false,
  } as Record<string, any>,

  onLoad(options) {
    const that = this
    const keyword = options.keyword ? decodeURIComponent(options.keyword) : ''

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    const title = keyword ? (pageTitle('search') || '搜索') : (pageTitle('product_all') || '全部商品')
    that.setData({ title })

    if (keyword) {
      that.setData({ keyword })
    }

    if (options.category_id) {
      that.setData({ category_id: options.category_id })
    } else if (options.brand_id) {
      that.setData({ category_id: options.brand_id })
    }

    that.loadData(false, '', keyword)
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadData(concat = false, page: number | string = '', keyword?: string) {
    const that = this
    const q = typeof keyword !== 'undefined' ? keyword : that.data.keyword

    if (concat !== true) {
      concat = false
    }
    if (!page) {
      page = ''
    }

    that.setData({ loading: true })

    http
      .get(route('search'), {
        q,
        module: 'product',
        category_id: that.data.category_id,
        by: that.data.by,
        sort: that.data.sort,
        page: page ? page : that.data.page,
      })
      .then(function (data) {
        let dataBox
        const dataNew = data && data.search_list ? data.search_list : []

        if (concat) {
          dataBox = that.data.search_list
          if (Array.isArray(dataNew) && dataNew.length > 0) {
            dataBox = dataBox.concat(dataNew)
          } else {
            that.setData({ nomore: true })
          }
        } else {
          dataBox = Array.isArray(dataNew) ? dataNew : []
        }

        that.setData({
          title: data && data.title ? data.title : that.data.title,
          keyword: q,
          search_list: dataBox,
          sort_list: data && Array.isArray(data.sort_list) ? data.sort_list : [],
          brand: data && data.brand ? data.brand : null,
          loading: false,
          loadpage: false,
        })
      })
      .catch(function (err) {
        that.setData({
          loading: false,
          loadpage: false,
          search_list: concat ? that.data.search_list : [],
        })
        douMsg(err.message || 'request_failed')
      })
  },

  douSort(e: WechatMiniprogram.TouchEvent) {
    this.setData({
      by: e.currentTarget.dataset.by || '',
      sort: e.currentTarget.dataset.sort || '',
      page: 1,
      nomore: false,
    })
    this.loadData(false, 1)
  },

  onReachBottom() {
    const that = this
    if (!that.data.nomore && !that.data.loadpage && !that.data.loading) {
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
