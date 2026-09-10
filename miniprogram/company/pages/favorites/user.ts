// pages/favorites/user.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    favorites_list: {},
    page: 1,
    startX: 0,
    startY: 0,
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

    that.setData({ title: pageTitle('favorites_user') })
    wx.setStorageSync('shareTitle', pageTitle('favorites_user'))

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })
    // 数据加载由 onShow 鉴权后触发
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadData(concat = false, page: number | string = '') {
    const that = this

    http
      .get(route('favorites.user'), {
        page: page ? page : that.data.page,
      })
      .then(function (data) {
        const dataNew = data.favorites_list || []
        let dataBox: any[] = []
        if (concat) {
          dataBox = that.data.favorites_list || []
          if (dataNew.length > 0) {
            dataBox = dataBox.concat(dataNew)
          } else {
            that.setData({ nomore: true })
          }
        } else {
          dataBox = dataNew
          that.setData({ nomore: dataNew.length === 0 })
        }

        that.setData({ favorites_list: dataBox, loadpage: false })
      })
      .catch(function (err) {
        if (err.message) {
          douMsg(err.message)
        }
        that.setData({ loadpage: false })
      })
  },

  onReachBottom() {
    const that = this
    if (!that.data.nomore) {
      const page = that.data.page + 1
      that.setData({ page, loadpage: true })
      setTimeout(function () {
        that.loadData(true)
      }, 1000)
    }
  },

  itemDel(e: WechatMiniprogram.TouchEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('favorites.user.destroy'), {
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
    const favorites_list = that.data.favorites_list

    const startX = that.data.startX
    const startY = that.data.startY
    const touchMoveX = e.changedTouches[0].clientX
    const touchMoveY = e.changedTouches[0].clientY
    const moveWidth = startX - touchMoveX

    const angle = that.angle({ X: startX, Y: startY }, { X: touchMoveX, Y: touchMoveY })
    if (Math.abs(angle) > 45) return

    let touchMove
    if (favorites_list[index].touch_move_start == 80) {
      if (moveWidth > 0) {
        return
      }
      touchMove = favorites_list[index].touch_move_start + moveWidth
    } else {
      let width = moveWidth
      if (width > 80) width = 80
      if (width < 0) width = 0
      touchMove = width
    }

    favorites_list[index].touch_move = touchMove
    that.setData({ favorites_list })
  },

  touchEnd(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const index = e.currentTarget.dataset.index
    const favorites_list = that.data.favorites_list

    const startX = that.data.startX
    const startY = that.data.startY
    const touchMoveX = e.changedTouches[0].clientX
    const touchMoveY = e.changedTouches[0].clientY
    let moveWidth = startX - touchMoveX

    const angle = that.angle({ X: startX, Y: startY }, { X: touchMoveX, Y: touchMoveY })
    if (Math.abs(angle) > 45) return

    if (moveWidth >= 0) {
      moveWidth = moveWidth > 20 ? 80 : 0
    } else {
      moveWidth = moveWidth < -20 ? 0 : 80
    }

    favorites_list[index].touch_move = moveWidth
    favorites_list[index].touch_move_start = moveWidth
    that.setData({ favorites_list })
  },

  angle(start: { X: number; Y: number }, end: { X: number; Y: number }) {
    const _X = end.X - start.X
    const _Y = end.Y - start.Y
    return (360 * Math.atan(_Y / _X)) / (2 * Math.PI)
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
