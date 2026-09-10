/**
 * 小程序入口 —— 注册 HTTP 拦截器 + 引导全局 store + 保留推广解析 / 自动更新 / 全局错误钩子。
 */

import { authStore } from './stores/auth.js'
import { bootstrapStores } from './stores/index.js'
import { http } from './services/http.js'
import { isDebugEnv } from './utils/env.js'
import { setPromotionUserSn } from './utils/promotion.js'
import { douLoading, mp_url, root_url } from './config/site.js'

App<IAppOption>({
  root_url,
  mp_url,
  douLoading,
  globalData: {
    statusBarHeight: '',
    windowHeight: '',
    menuButtonHeight: 0,
    navigationBarHeight: 0,
  },

  onLaunch(options) {
    // UNAUTHORIZED 统一登出（拦截器只装一次）
    http.onError(function (err) {
      if (err.code === 'UNAUTHORIZED') {
        authStore.logout()
      }
    })

    // 推广分销：onLaunch 第一时间解析 user_sn 落本地缓存
    this.parsePromotionFromLaunchOptions(options)

    // 全局 store 引导（hydrate 秒开 + 网络刷新）
    bootstrapStores()

    const windowInfo = wx.getWindowInfo()
    const deviceInfo = wx.getDeviceInfo()
    const { statusBarHeight, windowHeight } = windowInfo
    const { platform } = deviceInfo
    const { top, height } = wx.getMenuButtonBoundingClientRect()

    this.globalData.statusBarHeight = statusBarHeight
    this.globalData.windowHeight = windowHeight
    this.globalData.menuButtonHeight = height ? height : 32

    let navigationBarHeight: number
    if (top && top !== 0 && height && height !== 0) {
      navigationBarHeight = (top - statusBarHeight) * 2 + height
    } else {
      navigationBarHeight = platform === 'android' ? 48 : 40
    }
    this.globalData.navigationBarHeight = navigationBarHeight
    this.globalData.navigationBarAndStatusBarHeight = statusBarHeight + navigationBarHeight

    this.autoUpdate()

    // 非正式版开启微信内置 vConsole 浮层
    if (isDebugEnv()) {
      wx.setEnableDebug({ enableDebug: true })
    }
  },

  onShow(options) {
    // 从后台切回 / 推广卡片冷启再回前台时，再尝试解析一次
    this.parsePromotionFromLaunchOptions(options)
  },

  // 全局未捕获 JS 异常：始终入 Console，调试期额外弹 modal
  onError(err) {
    console.error('[App.onError]', err)
    if (isDebugEnv()) {
      wx.showModal({
        title: '[DEV] JS Error',
        content: String(err).slice(0, 600),
        showCancel: false,
      })
    }
  },

  // 未处理的 Promise 拒绝：仅入 Console（业务 .catch 已覆盖正常错误）
  onUnhandledRejection(res) {
    console.error('[UnhandledRejection]', res && res.reason)
  },

  // 路由到不存在的页面：入 Console，调试期弹 modal 暴露拼错的 path
  onPageNotFound(res) {
    console.error('[PageNotFound]', res)
    if (isDebugEnv()) {
      wx.showModal({
        title: '[DEV] PageNotFound',
        content: (res && res.path) || '',
        showCancel: false,
      })
    }
  },

  /**
   * 兼容多种打开来源解析推广候选 user_sn 并写入 storage：
   *   - scene 经 decodeURIComponent 后形如 "user_sn=946553"
   *   - query 形如 { user_sn: '946553' }
   * 最近一次扫码 / 分享胜出（覆盖旧值）。
   */
  parsePromotionFromLaunchOptions(options?: WechatMiniprogram.App.LaunchShowOption) {
    if (!options) {
      return
    }
    let userSn = ''
    if (options.query && options.query.user_sn) {
      userSn = options.query.user_sn
    } else if (options.scene) {
      try {
        const scene = decodeURIComponent(String(options.scene))
        const parts = scene.split('&')
        for (let i = 0; i < parts.length; i++) {
          const kv = parts[i].split('=')
          if (kv.length === 2 && kv[0] === 'user_sn') {
            userSn = kv[1]
            break
          }
        }
      } catch (e) {
        /* scene 解析失败静默忽略 */
      }
    }
    if (userSn) {
      setPromotionUserSn(userSn)
    }
  },

  autoUpdate() {
    if (wx.canIUse('getUpdateManager')) {
      const updateManager = wx.getUpdateManager()
      updateManager.onCheckForUpdate(function (res) {
        if (res.hasUpdate) {
          updateManager.onUpdateReady(function () {
            wx.showModal({
              title: '更新提示',
              content: '新版本已经准备好，是否重启应用？',
              success(r) {
                if (r.confirm) {
                  updateManager.applyUpdate()
                }
              },
            })
          })
          updateManager.onUpdateFailed(function () {
            wx.showModal({
              title: '已经有新版本了哟~',
              content: '新版本已经上线啦~，请您删除当前小程序，重新搜索打开哟~',
            })
          })
        }
      })
    }
  },
})
