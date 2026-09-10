// pages/health/show.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    health: null,
    followup_groups: [],
    is_work: false,
    edit_url: '',
  } as Record<string, any>,

  onLoad(options) {
    const that = this
    that.setData({ title: pageTitle('health_show') || '档案详情' });
    that.setData({ id: options.id,
      is_work: options.is_work === '1',
    })

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })
  },

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      that.loadDetail()
    })
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadDetail() {
    const that = this
    const url = that.data.is_work
      ? route('health.work.show', { id: that.data.id })
      : route('health.user.show', { id: that.data.id })
    http
      .get(url)
      .then(function (data) {
        that.setData({
          health: data.health,
          followup_groups: data.followup_groups || [],
          edit_url: data.edit_url || '',
          current_user_id: data.current_user_id || 0,
        })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  goEdit() {
    if (this.data.edit_url) {
      wx.navigateTo({ url: '/pages/health/health_apply?id=' + this.data.health.id })
    }
  },
})
