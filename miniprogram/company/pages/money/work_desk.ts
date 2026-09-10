// pages/money/work_desk.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'

Page({
  data: {
    log_list: [],
    work: [],
    money: '',
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

  onLoad(options) {
    const that = this

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    if (options.number) {
      that.setData({ number: options.number })
    }
    // 数据由 onShow 鉴权后加载
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadData(concat = false, page: number | string = '') {
    const that = this
    const number = that.data.number
    const money = that.data.money

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('money.work.search'), {
          page: page ? page : that.data.page,
          number,
          money,
        })
        .then(function (data) {
          let dataBox
          if (concat) {
            dataBox = that.data.log_list
            const dataNew = data.log_list
            if (dataNew) {
              dataBox = dataBox.concat(dataNew)
            } else {
              that.setData({ nomore: true })
            }
          } else {
            dataBox = data.log_list
          }

          that.setData({
            log_list: dataBox,
            work: data.work,
            user: data.user,
            pay_code_mode: data.pay_code_mode,
            loadpage: false,
          })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed', 'back')
        })
    })
  },

  useMoney(e: WechatMiniprogram.CustomEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('money.work.use'), {
          money: e.detail.value.money,
          number: e.detail.value.number,
        })
        .then(function (data) {
          that.setData({ money: data.money })
          that.loadData()
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
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

  douNavigateBack(e: WechatMiniprogram.TouchEvent) {
    const delta = e.currentTarget.dataset.delta
    wx.navigateBack({ delta: delta ? delta : 1 })
  },

  douRedirectTo(e: WechatMiniprogram.TouchEvent) {
    wx.redirectTo({ url: e.currentTarget.dataset.url })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },

  catSwitchTab(e: WechatMiniprogram.TouchEvent) {
    wx.switchTab({ url: e.currentTarget.dataset.url })
  },
})
