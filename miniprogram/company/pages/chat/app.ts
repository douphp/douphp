// pages/chat/app.ts AI助手对话（流式）
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { chatStream } from '../../services/chat.js'
import { route } from '../../utils/route.js'
import { douMsg, showShareMenu } from '../../utils/ui.js'

Page({
  data: {
    chat: null,
    model_keys: [],
    model_names: [],
    model_index: 0,
    sessions: [],
    current_session: null,
    messages: [],
    input_message: '',
    is_processing: false,
    show_sessions: false,
    scroll_into: '',
  } as Record<string, any>,

  slug: '',
  streamHandle: null as any,

  onLoad(options: Record<string, string>) {
    const that = this
    that.slug = options.slug || ''

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
    if (this.streamHandle) {
      this.streamHandle.abort()
    }
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  loadApp() {
    const that = this

    http
      .get(route('chat.app'), { slug: that.slug })
      .then(function (data) {
        if (data.no_subscription) {
          douMsg('您还没有有效的 AI 套餐订阅', '/pages/chat/package')
          return
        }

        const models = data.available_models || {}
        const keys = Object.keys(models)
        const names = keys.map(function (k) {
          return models[k]
        })

        that.setData({
          title: data.chat ? data.chat.name : '',
          chat: data.chat,
          quota_status: data.quota_status,
          model_keys: keys,
          model_names: names,
          model_index: 0,
        })
        wx.setStorageSync('shareTitle', data.chat ? data.chat.name : '')

        that.loadSessions(true)
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  loadSessions(autoSwitch = false) {
    const that = this

    http
      .get(route('chat.sessions'), { chat_id: that.data.chat.id })
      .then(function (data) {
        const sessions = data.sessions || []
        that.setData({ sessions })
        if (autoSwitch && sessions.length > 0) {
          that.switchSession(sessions[0].session_sn)
        }
      })
      .catch(function () {
        /* 会话列表加载失败不阻断对话 */
      })
  },

  switchSession(sessionSn: string) {
    const that = this

    http
      .get(route('chat.messages'), { session_sn: sessionSn })
      .then(function (data) {
        const messages = (data.messages || []).map(function (row: Record<string, any>) {
          return {
            type: row.role === 'user' ? 'user' : row.role === 'system' ? 'system' : 'ai',
            content: row.content,
          }
        })
        that.setData({
          current_session: data.session,
          messages,
          show_sessions: false,
        })
        that.scrollToBottom()
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  onSessionTap(e: WechatMiniprogram.TouchEvent) {
    this.switchSession(e.currentTarget.dataset.sn)
  },

  toggleSessions() {
    this.setData({ show_sessions: !this.data.show_sessions })
  },

  newSession() {
    this.setData({
      current_session: null,
      messages: [],
      show_sessions: false,
    })
    douMsg('新会话已就绪，请输入您的第一个问题')
  },

  onModelChange(e: WechatMiniprogram.PickerChange) {
    this.setData({ model_index: Number(e.detail.value) })
  },

  onInput(e: WechatMiniprogram.Input) {
    this.setData({ input_message: e.detail.value })
  },

  scrollToBottom() {
    const that = this
    const len = that.data.messages.length
    if (len > 0) {
      that.setData({ scroll_into: 'msg-' + (len - 1) })
    }
  },

  createSession(firstMessage: string): Promise<boolean> {
    const that = this
    const title = firstMessage.length > 20 ? firstMessage.substring(0, 20) + '...' : firstMessage
    const payload: Record<string, any> = {
      chat_id: that.data.chat.id,
      title,
    }
    const modelKey = that.data.model_keys[that.data.model_index]
    if (modelKey) {
      payload.model_id = modelKey
    }

    return http
      .post(route('chat.new_session'), payload)
      .then(function (data) {
        that.setData({
          current_session: data.session,
          sessions: [data.session].concat(that.data.sessions),
        })
        return true
      })
      .catch(function (err) {
        douMsg(err.message || '创建会话失败')
        return false
      })
  },

  sendMessage() {
    const that = this
    const message = (that.data.input_message || '').trim()
    if (!message) {
      douMsg('请输入消息内容')
      return
    }
    if (that.data.is_processing) {
      douMsg('请等待当前消息处理完成')
      return
    }

    const run = function () {
      const messages = that.data.messages.concat([
        { type: 'user', content: message },
        { type: 'ai', content: '', typing: true },
      ])
      that.setData({ messages, input_message: '', is_processing: true })
      that.scrollToBottom()

      const aiIndex = messages.length - 1
      let content = ''

      const payload: Record<string, any> = {
        prompt: message,
        session_sn: that.data.current_session.session_sn,
      }
      const modelKey = that.data.model_keys[that.data.model_index]
      if (modelKey) {
        payload.model_id = modelKey
      }

      that.streamHandle = chatStream(payload, {
        onContent(text: string) {
          content += text
          that.setData({
            ['messages[' + aiIndex + '].content']: content,
            ['messages[' + aiIndex + '].typing']: false,
          })
          that.scrollToBottom()
        },
        onError(msg: string) {
          const list = that.data.messages.slice(0, aiIndex)
          that.setData({ messages: list, is_processing: false })
          douMsg(msg)
        },
        onDone() {
          that.setData({
            ['messages[' + aiIndex + '].typing']: false,
            is_processing: false,
          })
          that.loadSessions()
        },
      })
    }

    if (!that.data.current_session) {
      that.createSession(message).then(function (ok) {
        if (ok) run()
      })
    } else {
      run()
    }
  },

  stopGeneration() {
    if (this.streamHandle) {
      this.streamHandle.abort()
      this.streamHandle = null
    }
    this.setData({ is_processing: false })
    douMsg('已停止生成')
  },

  onShareAppMessage() {
    return { title: wx.getStorageSync('shareTitle') }
  },
})
