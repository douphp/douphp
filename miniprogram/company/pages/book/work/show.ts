// pages/book/work/show.ts - 工作端预约详情
import { createStoreBindings } from '../../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../../stores/index.js'
import { http } from '../../../services/http.js'
import { route } from '../../../utils/route.js'
import { douMsg } from '../../../utils/ui.js'
import { pageTitle } from '../../../utils/page_title.js'

Page({
  data: {
    book: null,
    lang: {},
  } as Record<string, any>,

  onShow() {
    authStore.ensureLogin()
  },

  onLoad(options) {
    const that = this
    const id = options.id || ''

    that.setData({ title: pageTitle('book_work_show') || '预约详情' });
    that.setData({ id })

    wx.setStorageSync('shareTitle', pageTitle('book_work_show') || '预约详情')

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('book.work.show', { id }))
      .then(function (data) {
        that.setData({ book: data.book })
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

  confirmBook() {
    const that = this
    wx.showModal({
      title: that.data.lang.book_confirm || '确认预约',
      content: that.data.lang.book_confirm_confirm || '确定确认该预约？',
      success(res) {
        if (res.confirm) {
          http
            .post(route('book.work.confirm'), {
              id: that.data.id,
            })
            .then(function (data) {
              wx.showToast({ title: data.__message || '已确认', icon: 'success' })
              setTimeout(function () {
                wx.navigateBack()
              }, 1200)
            })
            .catch(function (err) {
              wx.showToast({ title: err.message || 'request_failed', icon: 'none' })
            })
        }
      },
    })
  },

  rejectBook() {
    const that = this
    wx.showModal({
      title: that.data.lang.book_reject || '拒绝预约',
      content: that.data.lang.book_reject_confirm || '确定拒绝该预约？',
      editable: true,
      placeholderText: that.data.lang.book_cancel_reason || '请输入拒绝原因',
      success(res) {
        if (res.confirm && res.content) {
          http
            .post(route('book.work.reject'), {
              id: that.data.id,
              reason: res.content,
            })
            .then(function (data) {
              wx.showToast({ title: data.__message || '已拒绝', icon: 'success' })
              setTimeout(function () {
                wx.navigateBack()
              }, 1200)
            })
            .catch(function (err) {
              wx.showToast({ title: err.message || 'request_failed', icon: 'none' })
            })
        }
      },
    })
  },

  completeBook() {
    const that = this
    wx.showModal({
      title: that.data.lang.book_complete || '标记完成',
      content: that.data.lang.book_complete_confirm || '确定标记为已完成？',
      success(res) {
        if (res.confirm) {
          http
            .post(route('book.work.complete'), {
              id: that.data.id,
            })
            .then(function (data) {
              wx.showToast({ title: data.__message || '已完成', icon: 'success' })
              setTimeout(function () {
                wx.navigateBack()
              }, 1200)
            })
            .catch(function (err) {
              wx.showToast({ title: err.message || 'request_failed', icon: 'none' })
            })
        }
      },
    })
  },

  checkinBook() {
    const that = this
    wx.showModal({
      title: that.data.lang.book_checkin || '签到核销',
      content: that.data.lang.book_checkin_confirm || '确定签到核销？',
      success(res) {
        if (res.confirm) {
          http
            .post(route('book.work.checkin'), {
              id: that.data.id,
            })
            .then(function (data) {
              wx.showToast({ title: data.__message || '已签到', icon: 'success' })
              setTimeout(function () {
                wx.navigateBack()
              }, 1200)
            })
            .catch(function (err) {
              wx.showToast({ title: err.message || 'request_failed', icon: 'none' })
            })
        }
      },
    })
  },

  goBack() {
    wx.navigateBack()
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
