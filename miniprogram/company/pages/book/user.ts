// pages/book/user.ts - 我的预约（新版）
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
    that.setData({ title: pageTitle('book_user') || '我的预约' })
    wx.setStorageSync('shareTitle', pageTitle('book_user') || '我的预约')
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
      .get(route('book.user'), {
        page: page || that.data.page,
      })
      .then(function (data) {
        if (!data.book_list && !concat) {
          wx.navigateTo({ url: '/pages/user/user' })
          return
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
        that.setData({ book_list: dataBox, loadpage: false })
      })
      .catch(function (err) {
        wx.showToast({ title: err.message || 'request_failed', icon: 'none' })
      })
  },

  cancelBook(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const id = e.currentTarget.dataset.id
    wx.showModal({
      title: '提示',
      content: '确定取消该预约？',
      success(res) {
        if (res.confirm) {
          http
            .post(route('book.user.cancel'), {
              id,
            })
            .then(function (data) {
              wx.showToast({ title: data.__message || '已取消', icon: 'success' })
              that.loadData()
            })
            .catch(function (err) {
              wx.showToast({ title: err.message || 'request_failed', icon: 'none' })
            })
        }
      },
    })
  },

  onReachBottom() {
    const that = this
    if (!that.data.nomore) {
      const page = that.data.page + 1
      that.setData({ page, loadpage: true })
      setTimeout(function () {
        that.loadData(true)
      }, 800)
    }
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
