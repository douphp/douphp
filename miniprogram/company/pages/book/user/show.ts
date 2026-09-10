// pages/book/user/show.ts - 预约详情
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

    that.setData({ title: pageTitle('book_user_show') || '预约详情' });
    that.setData({ id })

    wx.setStorageSync('shareTitle', pageTitle('book_user_show') || '预约详情')

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('book.user.show', { id }))
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

  cancelBook() {
    const that = this
    wx.showModal({
      title: that.data.lang.book_cancel || '取消预约',
      content: that.data.lang.book_cancel_confirm || '确定取消该预约？',
      success(res) {
        if (res.confirm) {
          http
            .post(route('book.user.cancel'), {
              id: that.data.id,
            })
            .then(function (data) {
              wx.showToast({ title: data.__message || '已取消', icon: 'success' })
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
