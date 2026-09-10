// pages/product/work.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { checkWorkPermission } from '../../services/permission.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    product_list: [],
    page: 1,
    nomore: false,
  } as Record<string, any>,

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      checkWorkPermission('product')
      that.setData({ page: 1, nomore: false })
      that.loadData(false, 1)
    })
  },

  onLoad() {
    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    const title = pageTitle('product_list')
    this.setData({ title })
    wx.setStorageSync('shareTitle', title || '')

    showShareMenu()
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadData(concat = false, page: number | string = '') {
    const that = this

    http
      .get(route('product.work'), {
        page: page ? page : that.data.page,
      })
      .then(function (data) {
        let dataBox
        if (concat) {
          dataBox = that.data.product_list
          const dataNew = data.product_list
          if (dataNew) {
            dataBox = dataBox.concat(dataNew)
          } else {
            that.setData({ nomore: true })
          }
        } else {
          dataBox = data.product_list
        }

        that.setData({ product_list: dataBox, loadpage: false })
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

  del(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const id = e.currentTarget.dataset.id

    checkWorkPermission('product')

    if (!id) {
      wx.showToast({ title: '参数错误', icon: 'error' })
      return
    }

    wx.showModal({
      title: '提示',
      content: '确定要删除此项吗？删除后不可恢复。',
      confirmText: '确定删除',
      confirmColor: '#ff4d4f',
      success(res) {
        if (res.confirm) {
          wx.showLoading({ title: '删除中...' })

          http
            .del(route('product.work.destroy', { id }), {
              id,
            })
            .then(function (data) {
              wx.hideLoading()
              wx.showToast({
                title: data.__message || '',
                icon: 'success',
                duration: 1500,
                success() {
                  that.loadData()
                },
              })
            })
            .catch(function (err) {
              wx.hideLoading()
              wx.showToast({ title: err.message || 'request_failed', icon: 'none' })
            })
        }
      },
    })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
