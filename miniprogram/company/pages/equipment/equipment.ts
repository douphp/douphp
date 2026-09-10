// pages/equipment/equipment.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    equipment_category: [],
    equipment_list: [],
    class: '',
    page: 1,
    nomore: false,
  } as Record<string, any>,

  onLoad(options) {
    const that = this

    that.setData({ title: pageTitle('equipment') })
    wx.setStorageSync('shareTitle', pageTitle('equipment'))

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
      .get(route('equipment'), {
        class: that.data.class,
        page: page ? page : that.data.page,
      })
      .then(function (data) {
        let dataBox: any[] = []
        if (concat) {
          dataBox = that.data.equipment_list
          const dataNew = data.equipment_list
          if (dataNew && dataNew.length > 0) {
            dataBox = dataBox.concat(dataNew)
          } else {
            that.setData({ nomore: true })
          }
        } else {
          dataBox = data.equipment_list || []
        }

        that.setData({
          equipment_list: dataBox,
          screen: data.screen || [],
          class: data.class || that.data.class,
          loadpage: false,
        })

        that.scrollLeft(that.data.currentNav)
      })
      .catch(function (err) {
        that.setData({ loadpage: false })
        douMsg(err.message || 'request_failed')
      })
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
