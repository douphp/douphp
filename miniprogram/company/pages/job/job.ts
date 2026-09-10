// pages/job/job.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    job_list: [],
    class: '',
    page: 1,
    index: 0,
    category_index: 0,
    type_index: 0,
    place_index: 0,
    category: '',
    type: '',
    place: '',
    nomore: false,
  } as Record<string, any>,

  onLoad(options) {
    const that = this

    that.setData({ title: pageTitle('job') })
    wx.setStorageSync('shareTitle', pageTitle('job'))

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    if (options.class) {
      that.setData({ class: options.class })
    }

    that.loadData()
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  onShow() {
    cartStore.refresh()
  },

  loadData(concat = false, page: number | string = '') {
    const that = this

    http
      .get(route('job'), {
        category: that.data.category,
        type: that.data.type,
        place: that.data.place,
        page: page ? page : that.data.page,
      })
      .then(function (data) {
        let dataBox
        if (concat) {
          dataBox = that.data.job_list
          const dataNew = data.job_list
          if (dataNew) {
            dataBox = dataBox.concat(dataNew)
          } else {
            that.setData({ nomore: true })
          }
        } else {
          dataBox = data.job_list
        }

        that.setData({
          job_list: dataBox,
          attribute: data.attribute,
          loadpage: false,
        })

        that.scrollLeft(that.data.currentNav)
      })
      .catch(function (err) {
        that.setData({ loadpage: false })
        douMsg(err.message || 'request_failed')
      })
  },

  categoryChange(e: WechatMiniprogram.PickerChange) {
    this.setData({
      category_index: e.detail.value,
      category: this.data.attribute.category[e.detail.value as any],
    })
    this.loadData()
  },

  typeChange(e: WechatMiniprogram.PickerChange) {
    this.setData({
      type_index: e.detail.value,
      type: this.data.attribute.type[e.detail.value as any],
    })
    this.loadData()
  },

  placeChange(e: WechatMiniprogram.PickerChange) {
    this.setData({
      place_index: e.detail.value,
      place: this.data.attribute.place[e.detail.value as any],
    })
    this.loadData()
  },

  douMenu(e: WechatMiniprogram.TouchEvent) {
    this.setData({
      class: e.currentTarget.dataset.class,
      page: 1,
      nomore: false,
      currentNav: e.currentTarget.dataset.index,
    })
    this.loadData()
  },

  douAll() {
    this.setData({ class: '', page: 1, nomore: false, currentNav: 0 })
    this.loadData()
  },

  scrollLeft(currentNav = 0) {
    const that = this
    const query = wx.createSelectorQuery()
    query.selectAll('.navScroll .item').boundingClientRect()
    query.exec(function (res: any) {
      let num = 0
      for (let i = 0; i < currentNav; i++) {
        num += res[0][i].width + 20
      }
      that.setData({ scrollLeft: Math.ceil(num) })
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

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
