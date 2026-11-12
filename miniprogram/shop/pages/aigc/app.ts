// pages/aigc/app.ts AI应用生成（动态表单 + 轮询 + 产物预览）
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { getApp, submit, poll, downloadWorkFile, openWorkFile } from '../../services/aigc.js'
import { hasRoute } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'

Page({
  data: {
    app: null,
    fields: [],
    quota_status: null,
    submitting: false,
    task_id: 0,
    task_status: '',
    result_files: [],
    error_message: '',
    audio_playing: false,
  } as Record<string, any>,

  slug: '',
  pollTimer: null as any,
  pollCount: 0,
  audio: null as any,

  onLoad(options: Record<string, string>) {
    const that = this

    that.slug = options.slug || ''
    wx.setStorageSync('shareTitle', '')
    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      that.loadApp()
    })
  },

  onUnload() {
    this.stopPolling()
    this.destroyAudio()
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadApp() {
    const that = this

    if (!hasRoute('aigc.app')) {
      douMsg('AI应用暂不可用')
      return
    }

    getApp(that.slug)
      .then(function (data) {
        const app = data.app || null
        that.setData({
          title: app ? app.name : '',
          app: app,
          fields: that.normalizeFields(app, data.field_list || []),
          quota_status: data.quota_status || null,
        })
        wx.setStorageSync('shareTitle', app ? app.name : '')
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  /**
   * 字段归一：补 options 勾选态、select 索引与默认值。
   */
  normalizeFields(app: any, list: any[]) {
    const lang = this.data.lang || {}

    if (!list.length) {
      const isAudio = app && app.type === 'audio'
      return [
        {
          name: isAudio ? lang.aigc_text || '合成文本' : lang.aigc_prompt || '生成主题 / 提示词',
          slug: isAudio ? 'text' : 'prompt',
          type: 'textarea',
          must_fill: 1,
          tip: '',
          placeholder: '',
          width: 12,
          options: [],
          value: '',
          index: 0,
          selected_label: '',
        },
      ]
    }

    const fields = []
    for (let i = 0; i < list.length; i++) {
      const raw = list[i]
      const options = []
      const rawOptions = raw.options || []
      for (let j = 0; j < rawOptions.length; j++) {
        options.push({
          value: rawOptions[j].value,
          label: rawOptions[j].label,
          checked: false,
          selected: !!rawOptions[j].selected,
        })
      }

      let value: any = raw.default_value == null ? '' : raw.default_value
      let index = 0

      if (raw.type === 'select' || raw.type === 'radio') {
        let found = -1
        for (let j = 0; j < options.length; j++) {
          if (options[j].selected) {
            found = j
            break
          }
        }
        if (found < 0 && options.length) {
          found = 0
        }
        index = found < 0 ? 0 : found
        value = found >= 0 && options[found] ? options[found].value : ''
      } else if (raw.type === 'checkbox') {
        const picked: string[] = []
        const defaults = String(value).split(/[|,]/)
        for (let j = 0; j < options.length; j++) {
          for (let k = 0; k < defaults.length; k++) {
            const item = defaults[k].trim()
            if (item !== '' && (item === options[j].value || item === options[j].label)) {
              picked.push(options[j].value)
              break
            }
          }
        }
        value = picked
      }

      for (let j = 0; j < options.length; j++) {
        if (raw.type === 'checkbox') {
          options[j].checked = value.indexOf(options[j].value) > -1
        } else if (raw.type === 'radio') {
          options[j].checked = options[j].value === value
        }
      }

      fields.push({
        name: raw.name,
        slug: raw.slug,
        type: raw.type,
        must_fill: raw.must_fill,
        tip: raw.tip || '',
        placeholder: raw.placeholder || '',
        width: raw.width || 12,
        options: options,
        value: value,
        index: index,
        selected_label: options.length && options[index] ? options[index].label : '',
      })
    }

    return fields
  },

  onInput(e: WechatMiniprogram.Input) {
    const index = Number(e.currentTarget.dataset.index)
    this.setData({ ['fields[' + index + '].value']: e.detail.value })
  },

  onPickerChange(e: WechatMiniprogram.PickerChange) {
    const index = Number(e.currentTarget.dataset.index)
    const pick = Number(e.detail.value)
    const field = this.data.fields[index]
    const option = field && field.options ? field.options[pick] : null
    this.setData({
      ['fields[' + index + '].index']: pick,
      ['fields[' + index + '].value']: option ? option.value : '',
      ['fields[' + index + '].selected_label']: option ? option.label : '',
    })
  },

  onRadioChange(e: WechatMiniprogram.RadioGroupChange) {
    const index = Number(e.currentTarget.dataset.index)
    const field = this.data.fields[index]
    const value = e.detail.value
    const options = []
    for (let i = 0; i < field.options.length; i++) {
      const option = field.options[i]
      options.push({ value: option.value, label: option.label, checked: option.value === value })
    }
    this.setData({
      ['fields[' + index + '].value']: value,
      ['fields[' + index + '].options']: options,
    })
  },

  onCheckboxChange(e: WechatMiniprogram.CheckboxGroupChange) {
    const index = Number(e.currentTarget.dataset.index)
    const field = this.data.fields[index]
    const values = e.detail.value || []
    const options = []
    for (let i = 0; i < field.options.length; i++) {
      const option = field.options[i]
      options.push({
        value: option.value,
        label: option.label,
        checked: values.indexOf(option.value) > -1,
      })
    }
    this.setData({
      ['fields[' + index + '].value']: values,
      ['fields[' + index + '].options']: options,
    })
  },

  onSubmit() {
    if (this.data.submitting) {
      return
    }

    const fields = this.data.fields || []
    const params: Record<string, any> = {}

    for (let i = 0; i < fields.length; i++) {
      const field = fields[i]
      const value = field.value
      const empty = value === '' || value === null || value === undefined || (Array.isArray(value) && value.length === 0)

      if (field.must_fill && empty) {
        douMsg(field.name + ' 为必填项')
        return
      }
      if (field.type === 'number' && value !== '' && isNaN(Number(value))) {
        douMsg(field.name + ' 必须是数字')
        return
      }

      params[field.slug] = value
    }

    this.startSubmit(params)
  },

  startSubmit(params: Record<string, any>) {
    const that = this

    that.stopPolling()
    that.destroyAudio()
    that.pollCount = 0
    that.setData({
      submitting: true,
      error_message: '',
      task_id: 0,
      task_status: '',
      result_files: [],
    })

    submit(that.slug, params)
      .then(function (data) {
        that.setData({ task_id: data.task_id || 0, task_status: data.status || '' })

        if (data.status === 'succeeded' && data.result && data.result.files && data.result.files.length) {
          that.applyResult(data.result)
          return
        }
        if (data.status === 'failed' || data.status === 'timeout') {
          that.fail(data.error)
          return
        }
        that.startPolling()
      })
      .catch(function (err) {
        that.fail(err.message)
      })
  },

  startPolling() {
    const that = this
    that.stopPolling()
    that.pollTimer = setTimeout(function () {
      that.doPoll()
    }, 2000)
  },

  doPoll() {
    const that = this
    if (!that.data.task_id || !that.data.submitting) {
      return
    }

    that.pollCount++
    poll(that.data.task_id)
      .then(function (data) {
        that.setData({ task_status: data.status || '' })

        if (data.status === 'succeeded') {
          that.applyResult(data.result || {})
          return
        }
        if (data.status === 'failed' || data.status === 'timeout') {
          that.fail(data.error)
          return
        }
        if (that.pollCount >= 150) {
          that.fail('生成超时，请稍后在作品列表查看')
          return
        }
        that.startPolling()
      })
      .catch(function (err) {
        that.fail(err.message)
      })
  },

  stopPolling() {
    if (this.pollTimer) {
      clearTimeout(this.pollTimer)
      this.pollTimer = null
    }
  },

  fail(message: string) {
    this.stopPolling()
    this.setData({
      submitting: false,
      error_message: message || '生成失败，请稍后重试',
    })
  },

  applyResult(result: Record<string, any>) {
    const raw = (result && result.files) || []
    const files = []
    for (let i = 0; i < raw.length; i++) {
      files.push({
        index: raw[i].index,
        name: raw[i].name,
        format: raw[i].format,
        local_path: '',
      })
    }

    this.stopPolling()
    this.setData({ submitting: false, task_status: 'succeeded', result_files: files })

    for (let i = 0; i < files.length; i++) {
      this.loadPreview(files[i], i)
    }
  },

  loadPreview(file: Record<string, any>, index: number) {
    const that = this
    const app = that.data.app || {}
    if (app.type !== 'image' && app.type !== 'video' && app.type !== 'audio') {
      return
    }

    downloadWorkFile(that.data.task_id, file.index, 'inline')
      .then(function (path) {
        that.setData({ ['result_files[' + index + '].local_path']: path })
      })
      .catch(function () {
        /* 预览失败时保留文件卡片，可稍后在作品页重试 */
      })
  },

  previewImage(e: WechatMiniprogram.TouchEvent) {
    const files = this.data.result_files || []
    const index = Number(e.currentTarget.dataset.index)
    const urls = []
    for (let i = 0; i < files.length; i++) {
      if (files[i].local_path) {
        urls.push(files[i].local_path)
      }
    }
    const current = files[index] && files[index].local_path ? files[index].local_path : urls[0] || ''
    if (!current) {
      return
    }
    wx.previewImage({ current: current, urls: urls })
  },

  toggleAudio(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const index = Number(e.currentTarget.dataset.index)
    const file = this.data.result_files[index]
    if (!file || !file.local_path) {
      return
    }

    if (!this.audio) {
      this.audio = wx.createInnerAudioContext()
      this.audio.onEnded(function () {
        that.setData({ audio_playing: false })
      })
      this.audio.onPause(function () {
        that.setData({ audio_playing: false })
      })
      this.audio.onPlay(function () {
        that.setData({ audio_playing: true })
      })
    }

    if (this.data.audio_playing) {
      this.audio.pause()
      return
    }

    this.audio.src = file.local_path
    this.audio.play()
  },

  openDocument(e: WechatMiniprogram.TouchEvent) {
    const index = Number(e.currentTarget.dataset.index)
    const file = this.data.result_files[index]
    if (!file) {
      return
    }

    openWorkFile(this.data.task_id, file.index, file.format).catch(function (err) {
      douMsg(err.message || '文件打开失败')
    })
  },

  destroyAudio() {
    if (this.audio) {
      this.audio.destroy()
      this.audio = null
    }
  },

  toPackage() {
    wx.navigateTo({ url: '/pages/aigc/package' })
  },

  onShareAppMessage() {
    return { title: wx.getStorageSync('shareTitle') || (this.data.app ? this.data.app.name : '') }
  },
})
