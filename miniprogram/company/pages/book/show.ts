// pages/book/show.ts - 预约项目详情 + 时段选择（新版）
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    item: null,
    date_list: [],
    time_list: [],
    selected_date: '',
    selected_time: '',
    title: '',
  } as Record<string, any>,

  onShow() {
    authStore.ensureLogin()
  },

  onLoad(options) {
    const that = this

    that.setData({ title: pageTitle('book_show') || '预约详情' })

    wx.setStorageSync('shareTitle', pageTitle('book_show') || '预约详情')

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('book.show', { id: options.id }))
      .then(function (data) {
        that.setData({
          item: data.item,
          content: data.item ? data.item.content : '',
          id: options.id,
        })
        that.loadDateList()
      })
      .catch(function (err) {
        wx.showToast({ title: err.message || 'request_failed', icon: 'none' })
      })
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadDateList(current_date = '') {
    const that = this

    http
      .get(route('book.date'), {
        id: that.data.id,
        current_date,
      })
      .then(function (data) {
        const dateList = data.date_list || []

        if (dateList.length === 0) {
          douMsg(that.data.lang.book_date_empty)
          that.setData({ date_list: [], selected_date: '' })
          return
        }

        const firstDate = dateList[0].date
        that.setData({ date_list: dateList, selected_date: firstDate })

        if (firstDate) {
          that.loadTimeList(firstDate, '')
        }
      })
      .catch(function (err) {
        wx.showToast({ title: err.message || '加载日期失败', icon: 'none' })
      })
  },

  selectDate(e: WechatMiniprogram.TouchEvent) {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return

      const date = e.currentTarget.dataset.date

      const dateList = that.data.date_list
      for (let i = 0; i < dateList.length; i++) {
        dateList[i].cur = dateList[i].date === date
      }

      that.setData({
        selected_date: date,
        selected_time: '',
        time_list: [],
        date_list: dateList,
      })

      that.loadTimeList(date, '')
    })
  },

  loadTimeList(date = '', current_time = '') {
    const that = this

    http
      .get(route('book.time'), {
        id: that.data.id,
        date,
        current_time,
      })
      .then(function (data) {
        that.setData({ time_list: data.time_list || [] })
      })
      .catch(function (err) {
        wx.showToast({ title: err.message || 'request_failed', icon: 'none' })
      })
  },

  selectTime(e: WechatMiniprogram.TouchEvent) {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return

      const item = e.currentTarget.dataset.item

      if (item.full) {
        wx.showToast({ title: '该时段已约满', icon: 'none' })
        return
      }
      if (item.closed) {
        wx.showToast({ title: '该时段已停诊', icon: 'none' })
        return
      }
      if (item.user_booked) {
        wx.showToast({ title: '您已预约此时段', icon: 'none' })
        return
      }

      const list = that.data.time_list.map(function (t: any) {
        return Object.assign({}, t, { cur: t.start_time === item.start_time })
      })

      that.setData({ time_list: list, selected_time: item.start_time })
    })
  },

  goToContact() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return

      if (!that.data.selected_date) {
        wx.showToast({ title: '请选择日期', icon: 'none' })
        return
      }
      if (!that.data.selected_time) {
        wx.showToast({ title: '请选择时段', icon: 'none' })
        return
      }
      wx.navigateTo({
        url:
          '/pages/book/contact?id=' +
          that.data.id +
          '&date=' +
          that.data.selected_date +
          '&start_time=' +
          that.data.selected_time,
      })
    })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
