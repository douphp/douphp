// pages/guestbook/guestbook.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    guestbook_list: [],
    page: 1,
    nomore: false,
    textAreaMaxLen: 200,
    inputValueLength: 0,
    title: '',
    contact: '',
    contact_info: '',
    content: '',
  } as Record<string, any>,

  onLoad() {
    const that = this

    that.setData({ title: pageTitle('guestbook') })

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
      .get(route('guestbook'), {
        page: page ? page : that.data.page,
      })
      .then(function (data) {
        let dataBox
        if (concat) {
          dataBox = that.data.guestbook_list
          const dataNew = data.guestbook_list
          if (dataNew) {
            dataBox = dataBox.concat(dataNew)
          } else {
            that.setData({ nomore: true })
          }
        } else {
          dataBox = data.guestbook_list
        }

        that.setData({ guestbook_list: dataBox, loadpage: false })
        wx.setStorageSync('shareTitle', data.title || '')
      })
      .catch(function (err) {
        that.setData({ loadpage: false })
        douMsg(err.message || 'request_failed')
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

  submit(e: WechatMiniprogram.CustomEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('guestbook.store'), {
          title: e.detail.value.title,
          contact: e.detail.value.contact,
          contact_info: e.detail.value.contact_info,
          content: e.detail.value.content,
        })
        .then(function (data) {
          if (data.is_water) {
            douMsg(data.is_water, '/pages/user/edit')
            return
          }
          wx.showToast({
            title: data.__message || that.data.lang.guestbook_insert_success,
            icon: 'success',
            duration: 1000,
            success() {
              that.setData({ title: '', contact: '', contact_info: '', content: '' })
              that.loadData()
            },
          })
        })
        .catch(function (err) {
          that.setData({ wrong: err.errors || {} })
        })
    })
  },

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      that.setData({ page: 1, nomore: false })
      that.loadData(false, 1)
    })
  },

  bindKeyInput(e: WechatMiniprogram.CustomEvent) {
    if (e.detail.value.length > 200) {
      this.setData({ inputValueLength: 200, inputValue: e.detail.value })
    } else {
      this.setData({ inputValueLength: e.detail.value.length, inputValue: e.detail.value })
    }
  },

  bindPickerChange(e: WechatMiniprogram.PickerChange) {
    this.setData({ index: e.detail.value })
  },

  onShareAppMessage() {
    return { title: wx.getStorageSync('shareTitle') }
  },
})
