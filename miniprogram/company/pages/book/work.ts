// pages/book/work.ts - 工作端预约列表（新版）
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    book_list: [],
    page: 1,
    nomore: false,
    loadpage: false,

    item_id: 0,
    status: '',
    date_start: '',
    date_end: '',
    keyword: '',

    item_list: [],
    status_list: [],
    selected_item_index: -1,
    selected_status_index: -1,
  } as Record<string, any>,

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      that.setData({ page: 1, nomore: false })
      that.loadData(false, 1)
    })
  },

  onLoad() {
    const that = this
    that.setData({ title: pageTitle('book_work') || '工作台' })
    wx.setStorageSync('shareTitle', pageTitle('book_work') || '工作台')
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
      .get(route('book.work'), {
        page: page || that.data.page,
        item_id: that.data.item_id,
        status: that.data.status,
        date_start: that.data.date_start,
        date_end: that.data.date_end,
        keyword: that.data.keyword,
      })
      .then(function (data) {
        if (!concat && !page) {
          that.setData({
            item_list: data.item_list || [],
            status_list: data.status_list || [],
          })
        }

        const dataNew = data.book_list || []
        let dataBox
        if (concat) {
          if (dataNew.length) {
            dataBox = that.data.book_list.concat(dataNew)
          } else {
            that.setData({ nomore: true, loadpage: false })
            return
          }
        } else {
          dataBox = dataNew
        }

        const uniqueMap: Record<string, any> = {}
        dataBox.forEach(function (item: any) {
          uniqueMap[item.id] = item
        })
        const uniqueList = []
        for (const key in uniqueMap) {
          uniqueList.push(uniqueMap[key])
        }

        that.setData({ book_list: uniqueList, loadpage: false })
      })
      .catch(function (err) {
        wx.showToast({ title: err.message || 'request_failed', icon: 'none' })
      })
  },

  onItemChange(e: WechatMiniprogram.CustomEvent) {
    const index = e.detail.value
    const item = this.data.item_list[index]
    this.setData({ selected_item_index: index, item_id: item ? item.id : 0 })
  },

  onStatusChange(e: WechatMiniprogram.CustomEvent) {
    const index = e.detail.value
    const status = this.data.status_list[index]
    this.setData({ selected_status_index: index, status: status ? status.value : '' })
  },

  onDateStartChange(e: WechatMiniprogram.CustomEvent) {
    this.setData({ date_start: e.detail.value })
  },

  onDateEndChange(e: WechatMiniprogram.CustomEvent) {
    this.setData({ date_end: e.detail.value })
  },

  resetFilter() {
    this.setData({
      item_id: 0,
      status: '',
      date_start: '',
      date_end: '',
      keyword: '',
      selected_item_index: -1,
      selected_status_index: -1,
      page: 1,
    })
    this.loadData()
  },

  doFilter() {
    this.setData({ page: 1 })
    this.loadData(false, 1)
  },

  onReachBottom() {
    const that = this
    if (!that.data.nomore) {
      const page = that.data.page + 1
      that.setData({ page, loadpage: true })
      setTimeout(function () {
        that.loadData(true, page)
      }, 800)
    }
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
