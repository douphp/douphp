// pages/vip/vip.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    package_list: [],
    current: 0,
    duration: 500,
  } as Record<string, any>,

  onShow() {
    authStore.ensureLogin()
  },

  onLoad() {
    const that = this

    that.setData({ title: pageTitle('vip') })
    wx.setStorageSync('shareTitle', pageTitle('vip_list'))

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('vip'), {
      })
      .then(function (data) {
        that.setData({
          package_list: data.package_list,
          content_field: data.content_field,
          package: data.package_list[that.data.current],
        })
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

  swiperbindchange(e: WechatMiniprogram.SwiperChange) {
    const that = this

    if (e.detail.source == 'touch' || e.detail.source == 'autoplay') {
      this.setData({ current: e.detail.current })

      if (this.data.current == 0 && this.data.rightcurrent > 1) {
        this.setData({ current: this.data.rightcurrent })
      } else {
        this.setData({ rightcurrent: this.data.current })
      }

      that.setData({ package: that.data.package_list[e.detail.current] })
    }
  },

  addToCart(e: WechatMiniprogram.TouchEvent) {
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('order.cart.store'), {
          module: e.currentTarget.dataset.module,
          item_id: e.currentTarget.dataset.item_id,
        })
        .then(function (data) {
          wx.navigateTo({
            url: data.mode == 'point' ? '/pages/order/checkout?mode=point' : '/pages/order/checkout',
          })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
