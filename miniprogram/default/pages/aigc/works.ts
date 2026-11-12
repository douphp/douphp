// pages/aigc/works.ts 我的作品
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { getWorks, deleteWork, downloadWorkFile, openWorkFile } from '../../services/aigc.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {
    work_list: [],
    type: '',
    types: [
      { key: '', label: '全部' },
      { key: 'image', label: '图片' },
      { key: 'video', label: '视频' },
      { key: 'audio', label: '音频' },
      { key: 'ppt', label: 'PPT' },
      { key: 'excel', label: 'Excel' },
    ],
    page: 1,
    nomore: false,
  } as Record<string, any>,

  audio: null as any,

  onLoad() {
    this.setData({ title: pageTitle('aigc_works') })
    wx.setStorageSync('shareTitle', pageTitle('aigc_works'))
    showShareMenu()

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })
  },

  onShow() {
    const that = this
    authStore.ensureLogin().then(function (ok) {
      if (!ok) return
      that.setData({ page: 1, nomore: false })
      that.loadData(false, 1)
    })
  },

  onUnload() {
    this.destroyAudio()
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  onReachBottom() {
    if (this.data.nomore) {
      return
    }
    const page = this.data.page + 1
    this.setData({ page: page })
    this.loadData(true, page)
  },

  switchType(e: WechatMiniprogram.TouchEvent) {
    const type = e.currentTarget.dataset.type || ''
    if (type === this.data.type) {
      return
    }
    this.setData({ type: type, page: 1, nomore: false })
    this.loadData(false, 1)
  },

  loadData(concat = false, page: number | string = 1) {
    const that = this

    getWorks(that.data.type, page)
      .then(function (data) {
        const rows = data.work_list || []
        const list = concat ? that.data.work_list.concat(rows) : rows
        that.setData({
          work_list: list,
          nomore: !rows.length,
        })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  previewWork(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const dataset = e.currentTarget.dataset
    const taskId = dataset.taskId
    const fileIndex = dataset.fileIndex
    const format = String(dataset.format || '').toLowerCase()
    const type = dataset.workType

    if (type === 'image') {
      downloadWorkFile(taskId, fileIndex, 'inline')
        .then(function (path) {
          wx.previewImage({ current: path, urls: [path] })
        })
        .catch(function () {
          douMsg('图片下载失败')
        })
      return
    }

    if (type === 'video') {
      downloadWorkFile(taskId, fileIndex, 'inline')
        .then(function (path) {
          wx.previewMedia({ sources: [{ url: path, type: 'video' }], current: 0 })
        })
        .catch(function () {
          douMsg('视频下载失败')
        })
      return
    }

    if (type === 'audio') {
      downloadWorkFile(taskId, fileIndex, 'inline')
        .then(function (path) {
          that.playAudio(path)
        })
        .catch(function () {
          douMsg('音频下载失败')
        })
      return
    }

    openWorkFile(taskId, fileIndex, format).catch(function (err) {
      douMsg(err.message || '文件打开失败')
    })
  },

  playAudio(path: string) {
    if (!this.audio) {
      this.audio = wx.createInnerAudioContext()
    }
    this.audio.src = path
    this.audio.play()
  },

  destroyAudio() {
    if (this.audio) {
      this.audio.destroy()
      this.audio = null
    }
  },

  removeWork(e: WechatMiniprogram.TouchEvent) {
    const that = this
    const taskId = e.currentTarget.dataset.taskId

    wx.showModal({
      title: '删除作品',
      content: '删除后不可恢复，确定删除？',
      success(res) {
        if (!res.confirm) {
          return
        }
        deleteWork(taskId)
          .then(function () {
            const list = []
            for (let i = 0; i < that.data.work_list.length; i++) {
              if (String(that.data.work_list[i].id) !== String(taskId)) {
                list.push(that.data.work_list[i])
              }
            }
            that.setData({ work_list: list })
            douMsg('已删除')
          })
          .catch(function (err) {
            douMsg(err.message || '删除失败')
          })
      },
    })
  },

  onShareAppMessage() {
    return { title: wx.getStorageSync('shareTitle') }
  },
})
