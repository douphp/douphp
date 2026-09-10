// pages/onepic/onepic.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    onepic_list: [],
    page: 1,
    nomore: false,
  } as Record<string, any>,

  onLoad() {
    const that = this

    that.setData({ title: pageTitle('onepic') })
    wx.setStorageSync('shareTitle', pageTitle('onepic'))

    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

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
      .get(route('onepic'), {
        page: page ? page : that.data.page,
      })
      .then(function (data) {
        let dataBox
        let imageBox
        if (concat) {
          dataBox = that.data.onepic_list
          const dataNew = data.onepic_list
          if (dataNew) {
            dataBox = dataBox.concat(dataNew)
          } else {
            that.setData({ nomore: true })
          }

          imageBox = that.data.onepic_list
          const imageNew = data.onepic_list
          if (imageNew) {
            imageBox = imageBox.concat(imageNew)
          }
        } else {
          dataBox = data.onepic_list
          imageBox = data.image_list
        }

        that.setData({
          onepic_list: dataBox,
          image_list: imageBox,
          loadpage: false,
        })
      })
      .catch(function (err) {
        that.setData({ loadpage: false })
        douMsg(err.message || 'request_failed')
      })
  },

  previewImage(e: WechatMiniprogram.TouchEvent) {
    wx.previewImage({
      showmenu: true,
      current: e.currentTarget.dataset.image,
      urls: this.data.image_list,
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
