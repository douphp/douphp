// pages/product/product.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    product: {},
    favorites: {},
    defined: [],
    coupon_list: [],
    open: {},
    attribute_list: [],
    box: {},
    comment_list: [],
    page: 1,
    nomore: false,

    indicatorDots: true,
    indicatorColor: 'rgba(0, 0, 0, .3)',
    indicatorActiveColor: '#FF0000',
    autoplay: true,
    interval: 5000,
    duration: 500,
    imgheight: '',

    number: 1,
    showShopPopup: false,
    animationData: {},
    attribute_data: {},
  } as Record<string, any>,

  onShow() {
    const that = this

    // order 未装时 route() 同步抛 Unknown route（不在 .catch 链内），需 feature 守卫
    if (commonStore.features.order !== true) {
      return
    }
    http
      .get(route('order.cart'), {
      })
      .then(function (data) {
        if (data.cart_number > 0) {
          that.setData({ cart_number: data.cart_number })
        }
      })
      .catch(function () {})
  },

  onLoad(options) {
    const that = this
    const itemId = options.id || options.item_id || options.product_id || ''

    that.setData({ title: pageTitle('product_detail') })

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('product.show', { id: itemId }))
      .then(function (data) {
        const product = data.product || {}
        that.setData({
          product,
          favorites: product.favorites || {},
          defined: data.defined || [],
          coupon_list: data.coupon_list || [],
          open: data.open || {},
        })

        wx.setStorageSync('shareTitle', product.title || '')
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })

    that.setData({
      item_id: itemId,
      mode: options.mode ? options.mode : 'money',
    })

    wx.setStorageSync('product_buy_mode', that.data.mode)

    that.attributeList()
    that.commentList(false, '', itemId)
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  addToCart(e: WechatMiniprogram.CustomEvent) {
    const that = this
    const action = e.detail.target.dataset.action

    // order 未装时 route() 同步抛 Unknown route（不在 .catch 链内），需 feature 守卫
    if (commonStore.features.order !== true) {
      return
    }
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('order.cart.store'), {
          post: JSON.stringify(e.detail.value),
          mode: that.data.mode,
          action,
        })
        .then(function (data) {
          if (data.mode == 'point' || action == 'buynow') {
            wx.navigateTo({ url: '/pages/order/checkout' })
          } else {
            wx.switchTab({ url: '/pages/order/order' })
          }
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  attributeList() {
    const that = this

    http
      .get(route('product.attribute_list'), {
        id: that.data.item_id,
        mode: that.data.mode,
        attribute_data: that.data.attribute_data,
      })
      .then(function (data) {
        that.setData({ attribute_list: data.attribute_list, box: data.box })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  commentList(concat = false, page: number | string = '', itemId?: string) {
    const that = this
    const productId = itemId || that.data.item_id

    if (!productId) {
      return
    }

    http
      .get(route('comment.list'), {
        module: 'product',
        item_id: productId,
        page: page ? page : that.data.page,
      })
      .then(function (data) {
        let dataBox
        if (concat) {
          dataBox = that.data.comment_list
          const dataNew = data.comment_list
          if (dataNew) {
            dataBox = dataBox.concat(dataNew)
          } else {
            that.setData({ nomore: true })
          }
        } else {
          dataBox = data.comment_list
        }

        that.setData({ comment_list: dataBox, loadpage: false })
      })
      .catch(function (err) {
        that.setData({ loadpage: false })
        douMsg(err.message || 'request_failed')
      })
  },

  selectAttribute(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const key = 'attribute_data_box[' + e.currentTarget.dataset.att_id + ']'

    that.setData({ [key]: e.currentTarget.dataset.value })

    const attribute_array = that.data.attribute_data_box
    const keyValuePairs = Object.keys(attribute_array).map(function (k) {
      return [k, attribute_array[k]]
    })

    that.setData({ attribute_data: Object.fromEntries(keyValuePairs) })

    that.attributeList()
  },

  onReachBottom() {
    const that = this
    if (!that.data.nomore) {
      const page = that.data.page + 1
      that.setData({ page, loadpage: true })
      setTimeout(function () {
        that.commentList(true)
      }, 1000)
    }
  },

  favorites(e: WechatMiniprogram.TouchEvent) {
    const that = this

    http
        .post(route('favorites.user.store'), {
        module: e.currentTarget.dataset.module || 'product',
        item_id: e.currentTarget.dataset.item_id,
      })
      .then(function (data) {
        if (data.__message) {
          douMsg(data.__message)
        }
        that.setData({ favorites: data.favorites })
      })
      .catch(function (err) {
        if (err.message) {
          douMsg(err.message)
        }
      })
  },

  getCoupon(e: WechatMiniprogram.TouchEvent) {
    const that = this

    http
        .post(route('coupon.claim'), {
        id: e.currentTarget.dataset.id,
      })
      .then(function (data) {
        if (data.__message) {
          douMsg(data.__message)
          that.setData({ coupon_list: data.coupon_list || [] })
        }
      })
      .catch(function (err) {
        douMsg(err.message || '请求失败')
      })
  },

  showCartForm() {
    this.setData({ showCartBg: true })

    const animation = wx.createAnimation({ duration: 400, timingFunction: 'ease', delay: 0 })
    this.animation = animation
    animation.translateY(0).step()
    this.setData({ animationData: animation.export() })
  },

  hideCartForm() {
    const animation = wx.createAnimation({ duration: 400, timingFunction: 'ease', delay: 0 })
    this.animation = animation
    animation.translateY(300).step()
    this.setData({ animationData: animation.export() })
    setTimeout(
      function (this: any) {
        this.setData({ showCartBg: false })
      }.bind(this),
      200
    )
  },

  bindMinus() {
    let number = this.data.number
    if (number > 1) {
      number--
    }
    this.setData({ number })
  },

  bindPlus() {
    let number = this.data.number
    number++
    this.setData({ number })
  },

  bindManual(e: WechatMiniprogram.CustomEvent) {
    this.setData({ number: e.detail.value })
  },

  onShareAppMessage() {
    return { title: wx.getStorageSync('shareTitle') }
  },

  tel(e: WechatMiniprogram.TouchEvent) {
    wx.makePhoneCall({ phoneNumber: e.currentTarget.dataset.tel })
  },

  imageLoad(e: WechatMiniprogram.CustomEvent) {
    const windowWidth = wx.getWindowInfo().windowWidth
    const height = e.detail.height
    const width = e.detail.width
    const imgheight = (windowWidth * height) / width + 'px'
    this.setData({ imgheight })
  },

  swiperbindchange(e: WechatMiniprogram.CustomEvent) {
    if (e.detail.source == 'touch' || e.detail.source == 'autoplay') {
      this.setData({ current: e.detail.current })

      if (this.data.current == 0 && this.data.rightcurrent > 1) {
        this.setData({ current: this.data.rightcurrent })
      } else {
        this.setData({ rightcurrent: this.data.current })
      }
    }
  },

  douSwitchTab(e: WechatMiniprogram.TouchEvent) {
    wx.switchTab({ url: e.currentTarget.dataset.url })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
