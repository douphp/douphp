// pages/search/search.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { commonStore } from '../../stores/index.js'
import { showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    keyword: '',
    keyword_list: [],
  } as Record<string, any>,

  onLoad(options) {
    const that = this

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    that.setData({ title: pageTitle('search') })

    that.setData({
      keyword: options.keyword ? decodeURIComponent(options.keyword) : '',
    })
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  onShow() {
    const that = this
    that.setData({
      keyword_list: wx.getStorageSync('keyword_list') ? wx.getStorageSync('keyword_list') : [],
    })
  },

  douSearch(e: WechatMiniprogram.CustomEvent) {
    const keyword = (e.detail.value.keyword || '').trim()
    if (!keyword) {
      return
    }

    const keyword_list = this.data.keyword_list.slice()
    if (keyword_list.indexOf(keyword) === -1) {
      keyword_list.unshift(keyword)
    }

    this.setData({ keyword, keyword_list })

    wx.setStorageSync('keyword_list', keyword_list)

    wx.navigateTo({ url: '/pages/search/list?keyword=' + encodeURIComponent(keyword) })
  },

  douKeyword(e: WechatMiniprogram.TouchEvent) {
    const keyword = (e.currentTarget.dataset.keyword || '').trim()
    if (!keyword) {
      return
    }

    this.setData({ keyword })

    wx.navigateTo({ url: '/pages/search/list?keyword=' + encodeURIComponent(keyword) })
  },

  delHistory() {
    this.setData({ keyword_list: [] })
    wx.setStorageSync('keyword_list', [])
  },

  onShareAppMessage() {
    return { title: wx.getStorageSync('shareTitle') }
  },
})
