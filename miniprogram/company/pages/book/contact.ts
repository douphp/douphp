// pages/book/contact.ts - 确认预约（新版）
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    item: null,
    contact_list: [],
    selected_contact_id: 0,
    id: '',
    date: '',
    start_time: '',
    end_time: '',
    price: 0,
    remark: '',
    custom_fields: [],
    custom: {},
    isPopupVisible: false,
  } as Record<string, any>,

  onShow() {
    authStore.ensureLogin()
  },

  onLoad(options) {
    const that = this

    that.setData({
      id: options.id || '',
      date: options.date || '',
      start_time: options.start_time || '',
      title: pageTitle('book') || '确认预约',
    })

    wx.setStorageSync('shareTitle', pageTitle('book') || '确认预约')
    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('book.contact'), {
        id: options.id,
        date: options.date,
        start_time: options.start_time,
      })
      .then(function (data) {
        const customFields = data.custom_fields || []
        const customInit: Record<string, any> = {}
        for (let i = 0; i < customFields.length; i++) {
          const f = customFields[i]
          if (f.type === 'select' && f.options && f.options.length > 0) {
            customInit[f.name] = f.options[0]
          } else if (f.type === 'checkbox') {
            customInit[f.name] = []
          } else {
            customInit[f.name] = ''
          }
        }
        that.setData({
          item: data.item,
          date: data.date,
          start_time: data.start_time,
          end_time: data.end_time,
          price: data.price,
          custom_fields: customFields,
          custom: customInit,
          time_format: data.date + ' ' + data.start_time + '-' + data.end_time,
        })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed', '/pages/book/show?id=' + options.id)
      })

    http
      .post(route('user.contact.list_json'), {
      })
      .then(function (data) {
        const contactList = data.contact_list || []
        const defaultId = contactList.length ? contactList[0].id : 0
        const defaultContact = contactList.length ? contactList[0] : null

        that.setData({
          contact_list: contactList,
          selected_contact_id: defaultId,
          contact: defaultContact,
        })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed', '/pages/book/show?id=' + options.id)
      })
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  contactPopup() {
    this.setData({ isPopupVisible: !this.data.isPopupVisible })
  },

  contactSelect(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const contactId = e.currentTarget.dataset.contact_id

    const contactList = that.data.contact_list
    for (let i = 0; i < contactList.length; i++) {
      if (contactList[i].id == contactId) {
        that.setData({ selected_contact_id: contactId, contact: contactList[i] })
        break
      }
    }

    that.contactPopup()
  },

  onRemarkInput(e: WechatMiniprogram.CustomEvent) {
    this.setData({ remark: e.detail.value })
  },

  onCustomInput(e: WechatMiniprogram.CustomEvent) {
    const name = e.currentTarget.dataset.name
    const custom = Object.assign({}, this.data.custom)
    custom[name] = e.detail.value
    this.setData({ custom })
  },

  onCustomPicker(e: WechatMiniprogram.CustomEvent) {
    const name = e.currentTarget.dataset.name
    const idx = parseInt(e.detail.value, 10)
    let field = null
    for (let i = 0; i < this.data.custom_fields.length; i++) {
      if (this.data.custom_fields[i].name === name) {
        field = this.data.custom_fields[i]
        break
      }
    }
    if (!field) return
    const custom = Object.assign({}, this.data.custom)
    custom[name] = field.options && field.options[idx] ? field.options[idx] : ''
    this.setData({ custom })
  },

  onCustomRadio(e: WechatMiniprogram.CustomEvent) {
    const name = e.currentTarget.dataset.name
    const custom = Object.assign({}, this.data.custom)
    custom[name] = e.detail.value
    this.setData({ custom })
  },

  onCustomCheckbox(e: WechatMiniprogram.CustomEvent) {
    const name = e.currentTarget.dataset.name
    const custom = Object.assign({}, this.data.custom)
    custom[name] = e.detail.value
    this.setData({ custom })
  },

  bookSubmit() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      if (!that.data.selected_contact_id) {
        wx.showToast({ title: '请选择预约人', icon: 'none' })
        return
      }

      const fields = that.data.custom_fields || []
      const custom = that.data.custom || {}
      for (let i = 0; i < fields.length; i++) {
        const f = fields[i]
        if (!f.required) {
          continue
        }
        const cv = custom[f.name]
        const empty =
          f.type === 'checkbox'
            ? !cv || !cv.length
            : !cv || (cv + '').replace(/^\s+|\s+$/g, '') === ''
        if (empty) {
          wx.showToast({ title: '请填写：' + f.name, icon: 'none' })
          return
        }
      }

      wx.showLoading({ title: '提交中...' })
      http
        .post(route('book.booking'), {
          id: that.data.id,
          date: that.data.date,
          start_time: that.data.start_time,
          contact_id: that.data.selected_contact_id,
          people_count: 1,
          remark: that.data.remark,
          custom: JSON.stringify(custom),
        })
        .then(function (data) {
          wx.hideLoading()
          douMsg(data.__message || '', '/pages/book/user', 1500)
        })
        .catch(function (err) {
          wx.hideLoading()
          douMsg(err.message || 'request_failed')
        })
    })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
