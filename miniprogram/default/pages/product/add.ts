// pages/product/add.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { filebox, fileDel } from '../../services/upload.js'
import { checkWorkPermission } from '../../services/permission.js'
import { route } from '../../utils/route.js'
import { douUrl } from '../../utils/url.js'
import { douMsg, douPageTo } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    id: 0,
    image: '',
    category_id: 0,
    product_category: [],
    cat_index: -1,
    selectedCategory: '',
    brand_id: 0,
    brand_list: [],
    brand_index: -1,
    selectedBrand: '',
    open: {},
    editorContent: '',
    attribute_list: [],
    attInputs: {},
  } as Record<string, any>,

  onShow() {
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      checkWorkPermission('product')
    })
  },

  onLoad(options) {
    const that = this

    that.setData({ title: pageTitle('product_add') })
    wx.setStorageSync('shareTitle', pageTitle('product_add'))

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('product.work.add'), {
        id: options.id,
      })
      .then(function (data) {
        const product = data.product || {}
        const categoryList = data.product_category
        const brandList = data.brand_list
        let catIndex = -1
        let brandIndex = -1

        if (categoryList && categoryList.length > 0) {
          for (let i = 0; i < categoryList.length; i++) {
            if (categoryList[i].id == product.category_id) {
              catIndex = i
              break
            }
          }
        }

        if (brandList && brandList.length > 0) {
          for (let i = 0; i < brandList.length; i++) {
            if (brandList[i].id == product.brand_id) {
              brandIndex = i
              break
            }
          }
        }

        that.setData(
          {
            id: options.id,
            product,
            product_category: data.product_category,
            cat_index: catIndex >= 0 ? catIndex : -1,
            selectedCategory: catIndex >= 0 ? categoryList[catIndex].name : product.name,
            brand_index: brandIndex >= 0 ? brandIndex : -1,
            selectedBrand: brandIndex >= 0 ? brandList[brandIndex].name : product.brand_name,
            img_list: data.img_list,
            category_id: product.category_id,
            brand_id: product.brand_id,
            image: product.image,
            brand_list: data.brand_list,
            editorContent: product.content,
            attribute_list: data.attribute_list || [],
          },
          function () {
            if (that.editorCtx) {
              that.setEditorContent()
            }
          }
        )
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })

    if (options.from) {
      that.setData({ from: options.from })
    }
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  productAction(e: WechatMiniprogram.CustomEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      checkWorkPermission('product')
      http
        .post(route('product.work.store'), {
          id: that.data.id || 0,
          title: e.detail.value.title,
          price: e.detail.value.price || 0,
          promote_price: e.detail.value.promote_price || 0,
          category_id: that.data.category_id || 0,
          brand_id: that.data.brand_id || 0,
          stock: e.detail.value.stock || 0,
          point: e.detail.value.point || 0,
          sort: e.detail.value.sort || 0,
          defined: e.detail.value.defined,
          content: that.data.editorContent,
          keywords: e.detail.value.keywords,
          description: e.detail.value.description,
          image: that.data.image,
        })
        .then(function (data) {
          douMsg(data.__message || '', '/pages/product/work')
        })
        .catch(function (err) {
          wx.showToast({ title: err.message || 'request_failed', icon: 'none' })
        })
    })
  },

  bindCatChange(e: WechatMiniprogram.CustomEvent) {
    const index = e.detail.value
    this.setData({
      cat_index: index,
      selectedCategory: this.data.product_category[index].name,
      category_id: this.data.product_category[index].id,
    })
  },

  bindBrandChange(e: WechatMiniprogram.CustomEvent) {
    const index = e.detail.value
    this.setData({
      brand_index: index,
      selectedBrand: this.data.brand_list[index].name,
      brand_id: this.data.brand_list[index].id,
    })
  },

  uploadImage(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const item_id = e.currentTarget.dataset.item_id
    const type = e.currentTarget.dataset.type || 'thumb'

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      checkWorkPermission('product')
      wx.chooseImage({
        count: 1,
        sizeType: ['original', 'compressed'],
        sourceType: ['album', 'camera'],
        success(res) {
          if (type == 'thumb') {
            that.setData({ imageTemp: res.tempFilePaths })
          }

          const tempFilePaths = res.tempFilePaths

          wx.uploadFile({
            url: route('product.work.upload'),
            filePath: tempFilePaths[0],
            formData: {
              type,
              item_id,
            },
            header: {
              Authorization: 'Bearer ' + (wx.getStorageSync('api_token') || ''),
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            name: 'image',
            success(res2) {
              let raw: any = {}
              try {
                raw = JSON.parse(res2.data)
              } catch (err) {
                wx.showToast({ title: 'request_failed', icon: 'none' })
                return
              }
              if (!raw || raw.code !== 'OK') {
                wx.showToast({ title: (raw && raw.message) || 'request_failed', icon: 'none' })
                return
              }
              if (type == 'content') {
                that.editorCtx.insertImage({ src: (raw.data || {}).file_url })
              } else {
                that.setData({ image: (raw.data || {}).image })
              }
            },
          })
        },
      })
    })
  },

  filebox(e: WechatMiniprogram.TouchEvent) {
    const that = this
    checkWorkPermission('product')
    filebox({ dataset: e.currentTarget.dataset } as any, function (img_list) {
      that.setData({ img_list })
    })
  },

  fileDel(e: WechatMiniprogram.TouchEvent) {
    const that = this
    checkWorkPermission('product')
    fileDel({ dataset: e.currentTarget.dataset } as any, function (img_list) {
      that.setData({ img_list })
    })
  },

  douPageTo(e: WechatMiniprogram.TouchEvent) {
    douPageTo(e.currentTarget.dataset.url)
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },

  editorReady() {
    const that = this
    wx.createSelectorQuery()
      .select('#editor')
      .context(function (res) {
        that.editorCtx = res.context
        if (that.data.editorContent) {
          that.setEditorContent()
        }
      })
      .exec()
  },

  setEditorContent() {
    if (this.editorCtx && this.data.editorContent) {
      this.editorCtx.setContents({ html: this.data.editorContent })
    }
  },

  editorInput(e: WechatMiniprogram.CustomEvent) {
    this.setData({ editorContent: e.detail.html })
  },

  editorFormat(e: WechatMiniprogram.TouchEvent) {
    const name = e.currentTarget.dataset.name
    if (this.editorCtx) {
      this.editorCtx.format(name)
    }
  },

  editorAlign(e: WechatMiniprogram.TouchEvent) {
    const value = e.currentTarget.dataset.value
    if (this.editorCtx) {
      this.editorCtx.format('textAlign', value)
    }
  },

  attInputChange(e: WechatMiniprogram.CustomEvent) {
    const att_id = e.currentTarget.dataset.att_id
    const inputs = this.data.attInputs
    if (!inputs[att_id]) inputs[att_id] = {}
    inputs[att_id].value = e.detail.value
    this.setData({ attInputs: inputs })
  },

  attRemarkChange(e: WechatMiniprogram.CustomEvent) {
    const att_id = e.currentTarget.dataset.att_id
    const inputs = this.data.attInputs
    if (!inputs[att_id]) inputs[att_id] = {}
    inputs[att_id].remark = e.detail.value
    this.setData({ attInputs: inputs })
  },

  attPriceChange(e: WechatMiniprogram.CustomEvent) {
    const att_id = e.currentTarget.dataset.att_id
    const inputs = this.data.attInputs
    if (!inputs[att_id]) inputs[att_id] = {}
    inputs[att_id].price_change = e.detail.value
    this.setData({ attInputs: inputs })
  },

  attAdd(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const dataset = e.currentTarget.dataset
    const att_id = dataset.att_id
    const inputs = that.data.attInputs[att_id] || {}

    if (!inputs.value) {
      wx.showToast({
        title: that.data.lang.attribute_value_value + that.data.lang.is_empty,
        icon: 'none',
      })
      return
    }

    http
      .post(douUrl('attribute', 'add'), {
        module: dataset.module,
        item_id: dataset.item_id,
        att_id,
        value: inputs.value || '',
        remark: inputs.remark || '',
        price_change: inputs.price_change || '',
      })
      .then(function (data) {
        that.refreshAttList(att_id, data.value_list)
        const inputsBox = that.data.attInputs
        inputsBox[att_id] = {}
        that.setData({ attInputs: inputsBox })
      })
      .catch(function (err) {
        wx.showToast({ title: err.message || 'request_failed', icon: 'none' })
      })
  },

  attDel(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const dataset = e.currentTarget.dataset
    const att_id = dataset.att_id

    wx.showModal({
      title: that.data.lang.del,
      content: dataset.del_value,
      success(res) {
        if (!res.confirm) return
        http
          .post(douUrl('attribute', 'del'), {
            module: dataset.module,
            item_id: dataset.item_id,
            att_id,
            del_value: dataset.del_value,
          })
          .then(function (data) {
            that.refreshAttList(att_id, data.value_list)
          })
          .catch(function (err) {
            wx.showToast({ title: err.message || 'request_failed', icon: 'none' })
          })
      },
    })
  },

  attImage(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const dataset = e.currentTarget.dataset
    const value_id = dataset.value_id
    const att_id = dataset.att_id

    wx.chooseImage({
      count: 1,
      sizeType: ['original', 'compressed'],
      sourceType: ['album', 'camera'],
      success(res) {
        wx.uploadFile({
          url: douUrl('attribute', 'image'),
          filePath: res.tempFilePaths[0],
          name: 'image',
          formData: {
            value_id,
          },
          header: { Authorization: 'Bearer ' + (wx.getStorageSync('api_token') || '') },
          success(res2) {
            let raw: any = {}
            try {
              raw = JSON.parse(res2.data)
            } catch (err) {
              wx.showToast({ title: 'request_failed', icon: 'none' })
              return
            }
            if (!raw || raw.code !== 'OK') {
              wx.showToast({ title: (raw && raw.message) || 'request_failed', icon: 'none' })
            } else {
              that.refreshAttList(att_id, (raw.data || {}).value_list)
            }
          },
        })
      },
    })
  },

  refreshAttList(att_id: string | number, value_list: any[]) {
    const list = this.data.attribute_list
    for (let i = 0; i < list.length; i++) {
      if (list[i].att_id == att_id) {
        list[i].value_list = value_list || []
        break
      }
    }
    this.setData({ attribute_list: list })
  },
})
