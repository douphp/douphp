// pages/book/book.ts - 预约分类引导页
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    class_list: [],
    title: '',
    lang: {},
  } as Record<string, any>,

  onShow() {
    authStore.ensureLogin()
  },

  onLoad() {
    const that = this

    that.setData({ title: pageTitle('book') || '预约' })

    wx.setStorageSync('shareTitle', pageTitle('book') || '预约')
    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('book.class'), {
      })
      .then(function (data) {
        that.setData({ class_list: data.class_list || [] })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
