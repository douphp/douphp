// pages/health/health_apply.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg, douPageTo } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    groups: [],
    draft_token: '',
    img_list: [],
    id: 0,
    is_edit: false,
    form_values: {},
  } as Record<string, any>,

  onLoad(options) {
    const that = this
    that.setData({ title: pageTitle('health_apply') || '健康登记' });
    that.setData({ id: options && options.id ? options.id : 0,
      is_edit: !!(options && options.id),
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
      that.loadFormData()
    })
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  decorateGroups(groups: any[], formValues: Record<string, any>) {
    const list = groups || []
    const values = formValues || {}
    for (let g = 0; g < list.length; g++) {
      const fields = list[g].fields || []
      for (let f = 0; f < fields.length; f++) {
        const field = fields[f]
        if (field.type === 'select' && field.options && field.options.length) {
          field.options_key = field.options.join('\u0001')
          const cur = values[field.slug] !== undefined ? values[field.slug] : field.value
          const idx = field.options.indexOf(cur)
          field.picker_index = idx >= 0 ? idx : 0
        }
      }
    }
    return list
  },

  loadFormData() {
    const that = this
    if (that.data.is_edit) {
      http
        .get(route('health.user.edit', { id: that.data.id }))
        .then(function (data) {
          const values = data.health && data.health.data_box ? data.health.data_box : {}
          const groups = that.decorateGroups(
            data.health && data.health.groups ? data.health.groups : [],
            values
          )
          that.setData({
            groups,
            draft_token: '',
            img_list: data.img_list || [],
            form_values: values,
          })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    } else {
      http
        .get(route('health.user.apply'), {
        })
        .then(function (data) {
          that.setData({
            groups: that.decorateGroups(data.groups || [], {}),
            draft_token: data.draft_token || '',
            img_list: data.img_list || [],
          })
        })
        .catch(function (err) {
          douMsg(err.message || 'request_failed')
        })
    }
  },

  fieldInput(e: WechatMiniprogram.CustomEvent) {
    const slug = e.currentTarget.dataset.slug
    const values = this.data.form_values || {}
    values[slug] = e.detail.value
    this.setData({ form_values: values })
  },

  fieldDateChange(e: WechatMiniprogram.CustomEvent) {
    const slug = e.currentTarget.dataset.slug
    const values = this.data.form_values || {}
    values[slug] = e.detail.value
    this.setData({ form_values: values })
  },

  fieldPickerChange(e: WechatMiniprogram.CustomEvent) {
    const slug = e.currentTarget.dataset.slug
    const key = e.currentTarget.dataset.options || ''
    const options = key ? key.split('\u0001') : []
    const idx = parseInt(e.detail.value, 10)
    const values = this.data.form_values || {}
    values[slug] = options[idx] || ''
    this.setData({ form_values: values })
  },

  douPageTo(e: WechatMiniprogram.TouchEvent) {
    douPageTo(e.currentTarget.dataset.url)
  },

  healthApply(e: WechatMiniprogram.CustomEvent) {
    const that = this
    const payload: Record<string, any> = e.detail.value || {}
    const values = that.data.form_values || {}
    for (const k in values) {
      if (values.hasOwnProperty(k)) {
        payload[k] = values[k]
      }
    }
    if (that.data.is_edit) {
      payload.id = that.data.id
    } else {
      payload.draft_token = that.data.draft_token
    }
    const onSuccess = function () {
      wx.redirectTo({ url: '/pages/health/user' })
    }
    const onError = function (err: { message?: string }) {
      douMsg(err.message || 'request_failed')
    }
    if (that.data.is_edit) {
      http.post(route('health.user.edit_post'), payload).then(onSuccess).catch(onError)
    } else {
      http.post(route('health.user.apply_post'), payload).then(onSuccess).catch(onError)
    }
  },
})
