// pages/book/list.ts - 预约项目列表（含按排班视图）
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

// 上下午分界时间（12:00，与后端保持一致，如需修改仅改此处）
const NOON = '12:00:00'

Page({
  data: {
    item_list: [],
    class_id: 0,
    title: '',
    lang: {},
    view: 'doctor',
    schedule_date_list: [],
    schedule_list: [],
    schedule_selected_date: '',
    panel_time_list: [],
    timePanelVisible: false,
    schedule_doctor_id: 0,
    schedule_session: 'am',
    schedule_selected_time: '',
  } as Record<string, any>,

  _scheduleLoaded: false,

  onShow() {
    authStore.ensureLogin()
  },

  onLoad(options) {
    const that = this
    const class_id = options.class_id || 0

    that.setData({ title: pageTitle('book') || '预约' });
    that.setData({ class_id })

    wx.setStorageSync('shareTitle', pageTitle('book') || '预约')
    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .get(route('book'), {
        class_id,
      })
      .then(function (data) {
        that.setData({ item_list: data.item_list || [] })
      })
      .catch(function (err) {
        wx.showToast({ title: err.message || 'request_failed', icon: 'none' })
      })
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  switchView(e: WechatMiniprogram.TouchEvent) {
    const view = e.currentTarget.dataset.view
    this.setData({ view })
    if (view === 'schedule' && !this._scheduleLoaded) {
      this.loadSchedule('')
    }
  },

  loadSchedule(date: string) {
    const that = this
    if (!that.data.class_id) return

    http
      .get(route('book.schedule'), {
        class_id: that.data.class_id,
        date: date || '',
      })
      .then(function (data) {
        that._scheduleLoaded = true
        const targetDate = date || data.date
        const dateList = (data.date_list || []).map(function (d: any) {
          return Object.assign({}, d, { cur: d.date === targetDate })
        })
        that.setData({
          schedule_date_list: dateList,
          schedule_selected_date: targetDate,
          schedule_list: data.schedule_list || [],
        })
      })
      .catch(function (err) {
        wx.showToast({ title: err.message || 'request_failed', icon: 'none' })
      })
  },

  scheduleDateSelect(e: WechatMiniprogram.TouchEvent) {
    const date = e.currentTarget.dataset.date
    const dateList = this.data.schedule_date_list.map(function (d: any) {
      return Object.assign({}, d, { cur: d.date === date })
    })
    this.setData({
      schedule_date_list: dateList,
      schedule_selected_date: date,
      schedule_list: [],
      schedule_selected_time: '',
    })
    this.loadSchedule(date)
  },

  openTimePanel(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const doctorId = e.currentTarget.dataset.id
    const session = e.currentTarget.dataset.session

    let doctor = null
    for (let i = 0; i < that.data.schedule_list.length; i++) {
      if (that.data.schedule_list[i].id === doctorId) {
        doctor = that.data.schedule_list[i]
        break
      }
    }
    if (!doctor) return
    if (session === 'am' && !doctor.am_available) return
    if (session === 'pm' && !doctor.pm_available) return

    that.setData({
      schedule_doctor_id: doctorId,
      schedule_session: session,
      panel_time_list: [],
      schedule_selected_time: '',
    })

    http
      .get(route('book.time'), {
        id: doctorId,
        date: that.data.schedule_selected_date,
        current_time: '',
      })
      .then(function (data) {
        const all = data.time_list || []
        const filtered = all.filter(function (t: any) {
          return session === 'am' ? t.start_time < NOON : t.start_time >= NOON
        })
        that.setData({ panel_time_list: filtered, timePanelVisible: true })
      })
      .catch(function (err) {
        wx.showToast({ title: err.message || 'request_failed', icon: 'none' })
      })
  },

  closeTimePanel() {
    this.setData({ timePanelVisible: false })
  },

  selectPanelTime(e: WechatMiniprogram.TouchEvent) {
    const item = e.currentTarget.dataset.item
    if (item.full || item.closed || item.user_booked) {
      wx.showToast({ title: item.status_tip || '该时段不可预约', icon: 'none' })
      return
    }
    const list = this.data.panel_time_list.map(function (t: any) {
      return Object.assign({}, t, { cur: t.start_time === item.start_time })
    })
    this.setData({ panel_time_list: list, schedule_selected_time: item.start_time })
  },

  goToContactFromPanel() {
    if (!this.data.schedule_selected_date) {
      wx.showToast({ title: '请选择日期', icon: 'none' })
      return
    }
    if (!this.data.schedule_selected_time) {
      wx.showToast({ title: '请选择时段', icon: 'none' })
      return
    }
    wx.navigateTo({
      url:
        '/pages/book/contact?id=' +
        this.data.schedule_doctor_id +
        '&date=' +
        this.data.schedule_selected_date +
        '&start_time=' +
        this.data.schedule_selected_time,
    })
  },

  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    wx.navigateTo({ url: e.currentTarget.dataset.url })
  },
})
