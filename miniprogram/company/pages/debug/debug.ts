// pages/debug/debug.ts
import { getLastRequestId } from '../../services/http.js'
import { isDebugEnv } from '../../utils/env.js'
import { getPromotionUserSn } from '../../utils/promotion.js'
import { douMsg } from '../../utils/ui.js'

const app = getApp<IAppOption>()

/** 鉴权凭证脱敏：只露尾 6 位，避免调试页/复制粘贴泄露完整 token */
function maskToken(token: string): string {
  if (!token) {
    return ''
  }
  return '...' + token.slice(-6)
}

Page({
  data: {
    title: '调试信息',
    rows: [],
  } as Record<string, any>,

  onLoad() {
    // 正式版兜底：即便被手动导航进入也立刻退出，不展示任何内部信息
    if (!isDebugEnv()) {
      wx.navigateBack()
      return
    }
    this.loadInfo()
  },

  onShow() {
    if (isDebugEnv()) {
      this.loadInfo()
    }
  },

  loadInfo() {
    let env = ''
    let appId = ''
    try {
      const info = wx.getAccountInfoSync().miniProgram
      env = info.envVersion || ''
      appId = info.appId || ''
    } catch (e) {
      env = ''
      appId = ''
    }

    const rows = [
      { label: 'envVersion', value: env || '(unknown)' },
      { label: 'appId', value: appId || '(unknown)' },
      { label: 'root_url', value: app.root_url || '' },
      { label: 'mp_url', value: app.mp_url || '' },
      { label: 'user_id', value: String(wx.getStorageSync('user_id') || '') },
      { label: 'api_token', value: maskToken(String(wx.getStorageSync('api_token') || '')) },
      { label: 'promotion_user_sn', value: getPromotionUserSn() || '' },
      { label: 'last_request_id', value: getLastRequestId() || '' },
    ]

    this.setData({ rows })
  },

  copyAll() {
    const lines = this.data.rows.map(function (r: { label: string; value: string }) {
      return r.label + ': ' + r.value
    })
    wx.setClipboardData({
      data: lines.join('\n'),
      success() {
        douMsg('已复制')
      },
    })
  },
})
