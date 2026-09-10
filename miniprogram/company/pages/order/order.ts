// pages/order/order.ts - 购物车
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'

Page({
  data: {
    startX: 0,
    startY: 0,
  } as Record<string, any>,

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      that.loadData()
    })
  },

  onLoad() {
    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    wx.setStorageSync('product_buy_mode', 'money')
    // 首次进入也走 onShow 中的鉴权串联
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadData() {
    const that = this

    http
      .get(route('order'), {
      })
      .then(function (data) {
        that.setData({ title: data.title, cart: data.cart })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  changeNumber(e: WechatMiniprogram.TouchEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .put(route('order.cart.update', { id: e.currentTarget.dataset.id }), {
          action: e.currentTarget.dataset.action,
          id: e.currentTarget.dataset.id,
        })
        .then(function () {
          that.loadData()
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  touchStart(e: WechatMiniprogram.TouchEvent) {
    this.setData({
      startX: e.changedTouches[0].clientX,
      startY: e.changedTouches[0].clientY,
    })
  },

  touchMove(e: WechatMiniprogram.TouchEvent) {
    const that = this

    const index = e.currentTarget.dataset.index
    const cart = that.data.cart

    const startX = that.data.startX
    const startY = that.data.startY
    const touchMoveX = e.changedTouches[0].clientX
    const touchMoveY = e.changedTouches[0].clientY
    let moveWidth = startX - touchMoveX

    const angle = that.angle({ X: startX, Y: startY }, { X: touchMoveX, Y: touchMoveY })

    if (Math.abs(angle) > 45) return

    let touchMove
    if (cart['list'][index].touch_move_start == 80) {
      if (moveWidth > 0) {
        return
      } else {
        touchMove = cart['list'][index].touch_move_start + moveWidth
      }
    } else {
      if (moveWidth > 80) moveWidth = 80
      if (moveWidth < 0) moveWidth = 0
      touchMove = moveWidth
    }

    cart['list'][index].touch_move = touchMove

    that.setData({ cart })
  },

  touchEnd(e: WechatMiniprogram.TouchEvent) {
    const that = this

    const index = e.currentTarget.dataset.index
    const cart = that.data.cart

    const startX = that.data.startX
    const startY = that.data.startY
    const touchMoveX = e.changedTouches[0].clientX
    const touchMoveY = e.changedTouches[0].clientY
    let moveWidth = startX - touchMoveX

    const angle = that.angle({ X: startX, Y: startY }, { X: touchMoveX, Y: touchMoveY })

    if (Math.abs(angle) > 45) return

    if (moveWidth >= 0) {
      if (moveWidth > 20) {
        moveWidth = 80
      } else {
        moveWidth = 0
      }
    } else {
      if (moveWidth < -20) {
        moveWidth = 0
      } else {
        moveWidth = 80
      }
    }

    cart['list'][index].touch_move = moveWidth
    cart['list'][index].touch_move_start = moveWidth

    that.setData({ cart })
  },

  angle(start: { X: number; Y: number }, end: { X: number; Y: number }) {
    const _X = end.X - start.X
    const _Y = end.Y - start.Y
    return (360 * Math.atan(_Y / _X)) / (2 * Math.PI)
  },

  itemDel(e: WechatMiniprogram.TouchEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .del(route('order.cart.destroy', { id: e.currentTarget.dataset.id }), {
          id: e.currentTarget.dataset.id,
        })
        .then(function () {
          that.loadData()
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
