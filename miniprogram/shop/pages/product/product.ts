// pages/product/product.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { hasRoute, route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'
import { startQuoteCheckout } from '../../utils/checkout.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    product: {},
    favorites: {},
    metadata_list: [],
    coupon_list: [],
    open: {},
    attribute_list: [],
    box: {},
    comment_list: [],
    page: 1,
    nomore: false,

    current: 0,
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
    cartStore.refresh().then(function () {
      that.setData({ cart_number: cartStore.number })
    })
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
        // 与 PC 端 product.dwt 一致：仅展示有值的文本类扩展字段
        const metadataList = (product.metadata_list || []).filter(function (meta: any) {
          return (
            meta &&
            meta.value !== '' &&
            meta.type !== 'image' &&
            meta.type !== 'gallery' &&
            meta.type !== 'file' &&
            meta.type !== 'editor'
          )
        })
        that.setData({
          product,
          favorites: product.favorites || {},
          metadata_list: metadataList,
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
    const action = e.detail.target.dataset.action || 'addtocart'

    // order 未装时 route() 同步抛 Unknown route（不在 .catch 链内），需 feature 守卫
    if (commonStore.features.order !== true) {
      return
    }
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return

      const form = e.detail.value || {}
      const isQuote = action === 'buynow' || that.data.mode === 'point'

      // 立即购买 / 积分兑换：走报价单，不写入购物车
      if (isQuote) {
        startQuoteCheckout(Object.assign({ mode: that.data.mode }, form)).catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
        return
      }

      that.submitCart(form, false)
    })
  },

  submitCart(form: Record<string, any>, replace: boolean) {
    const that = this

    http
      .post(
        route('order.cart.store'),
        Object.assign(
          {
            mode: that.data.mode,
            action: 'addtocart',
            replace: replace ? 1 : 0,
          },
          form
        )
      )
      .then(function (data) {
        if (typeof data.cart_number === 'number') {
          cartStore.setNumber(data.cart_number)
          that.setData({ cart_number: data.cart_number })
        }
        that.hideCartForm()
        douMsg(that.data.lang.order_cart_added || '已加入购物车')
      })
      .catch(function (err) {
        if (err && err.data && err.data.reason === 'cart_module_conflict') {
          wx.showModal({
            title: that.data.lang.order_cart || '购物车',
            content: err.message,
            success(res) {
              if (res.confirm) {
                that.submitCart(form, true)
              }
            },
          })
          return
        }
        douMsg(err.message || 'request_failed')
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
        // attribute 可选模块未装时后端返回空 data，setData(undefined) 会告警
        that.setData({ attribute_list: data.attribute_list || [], box: data.box || {} })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  commentList(concat = false, page: number | string = '', itemId?: string) {
    const that = this
    const productId = itemId || that.data.item_id

    // comment 为可选模块，未装时后端不产出 comment.list 路由（route() 同步抛错，不在 .catch 链内）
    if (!productId || !hasRoute('comment.list')) {
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
          if (dataNew && dataNew.length) {
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
    // 触底只用于评论分页，comment 未装时不进入加载态
    if (!that.data.nomore && hasRoute('comment.list')) {
      const page = that.data.page + 1
      that.setData({ page, loadpage: true })
      setTimeout(function () {
        that.commentList(true)
      }, 1000)
    }
  },

  favorites(e: WechatMiniprogram.TouchEvent) {
    const that = this

    // favorites 为可选模块，未装时无对应命名路由
    if (!hasRoute('favorites.user.store')) {
      return
    }

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

    // coupon 为可选模块，未装时无对应命名路由
    if (!hasRoute('coupon.claim')) {
      return
    }

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
    if (number < 999) {
      number++
    }
    this.setData({ number })
  },

  bindManual(e: WechatMiniprogram.CustomEvent) {
    let number = parseInt(e.detail.value, 10)
    if (!number || number < 1) {
      number = 1
    }
    if (number > 999) {
      number = 999
    }
    this.setData({ number })
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

  // 受控 current 只做同步，首尾衔接交给 swiper 的 circular，不再手动回跳
  swiperbindchange(e: WechatMiniprogram.CustomEvent) {
    if (e.detail.source === 'touch' || e.detail.source === 'autoplay') {
      this.setData({ current: e.detail.current })
    }
  },

  douSwitchTab(e: WechatMiniprogram.TouchEvent) {
    wx.switchTab({ url: e.currentTarget.dataset.url })
  },

  // 评论图片预览（product.wxml 中 comment.img_list 图片）
  previewImage(e: WechatMiniprogram.TouchEvent) {
    const image = e.currentTarget.dataset.image
    if (!image) {
      return
    }
    wx.previewImage({ current: image, urls: [image] })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
