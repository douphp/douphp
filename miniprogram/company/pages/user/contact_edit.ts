// pages/user/contact_edit.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, douPageTo, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    contact: [],
  } as Record<string, any>,

  onShow() {
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
    })
  },

  onLoad(options) {
    const that = this

    that.setData({ title: pageTitle('user_contact_edit') })

    wx.setStorageSync('shareTitle', pageTitle('user_contact_edit'))
    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('user.contact.edit'), {
        id: options.id,
      })
      .then(function (data) {
        that.setData({
          contact: data.contact || {},
          selectedProvince: data.contact ? data.contact.province || '' : '',
        })
        that.provinceList()

        if (data.contact && data.contact.city) {
          that.setData({ selectedCity: data.contact.city })
          that.cityList()
        }

        if (data.contact && data.contact.district) {
          that.setData({ selectedDistrict: data.contact.district })
          that.districtList()
        }
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

  contactAction(e: WechatMiniprogram.CustomEvent) {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('user.contact.update'), {
          name: e.detail.value.name,
          phone: e.detail.value.phone,
          id_card: e.detail.value.id_card,
          province: that.data.selectedProvince || '',
          city: that.data.selectedCity || '',
          district: that.data.selectedDistrict || '',
          address: e.detail.value.address,
          tag: e.detail.value.tag,
          id: that.data.contact.id,
        })
        .then(function () {
          if (that.data.from == 'checkout') {
            wx.redirectTo({ url: '/pages/order/checkout?contact_id=' + that.data.contact.id })
          } else {
            douPageTo('/pages/user/contact')
          }
        })
        .catch(function (err) {
          douMsg(err.message || '')
        })
    })
  },

  provinceList() {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('user.area'), {
          type: 'province',
          current: that.data.selectedProvince || '',
        })
        .then(function (data) {
          that.setData({ province_list: data.area_list })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  handleProvinceChange(e: WechatMiniprogram.CustomEvent) {
    const index = e.detail.value

    this.setData({
      selectedProvince: this.data.province_list[index].name,
      selectedCity: '',
      selectedDistrict: '',
    })

    this.cityList()
  },

  cityList() {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('user.area'), {
          type: 'city',
          parent: that.data.selectedProvince,
          current: that.data.selectedCity || '',
        })
        .then(function (data) {
          that.setData({ city_list: data.area_list })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  handleCityChange(e: WechatMiniprogram.CustomEvent) {
    const index = e.detail.value

    this.setData({
      selectedCity: this.data.city_list[index].name,
      selectedDistrict: '',
    })

    this.districtList()
  },

  districtList() {
    const that = this

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      http
        .post(route('user.area'), {
          type: 'district',
          parent: that.data.selectedCity,
          current: that.data.selectedDistrict || '',
        })
        .then(function (data) {
          that.setData({ district_list: data.area_list })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    })
  },

  handleDistrictChange(e: WechatMiniprogram.CustomEvent) {
    const index = e.detail.value

    this.setData({ selectedDistrict: this.data.district_list[index].name })
  },

  douPageTo(e: WechatMiniprogram.TouchEvent) {
    douPageTo(e.currentTarget.dataset.url)
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
