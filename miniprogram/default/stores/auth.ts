/**
 * 登录态 store —— 取代旧 util.auth() / app.globalData.auth。
 *
 * storage 真相源：api_token（鉴权凭证，经 Authorization: Bearer 请求头回传）/ user_id（仅展示）/ loginEd。
 * 模块降级：commonStore.features.user === false 时 is_login 恒 false、不发任何请求。
 */

import { action, observable, runInAction } from '../libs/mobx-miniprogram/index.js'
import type { AuthState } from '../types/store'
import { http } from '../services/http.js'
import { clearPromotionUserSn } from '../utils/promotion.js'
import { route } from '../utils/route.js'
import { commonStore } from './common.js'

const DEFAULT_LOGIN_REDIRECT = '/pages/user/login_weixin'

/** user/index 原始返回（含 dou.auth 登录态聚合） */
interface UserIndexResponse {
  dou?: {
    auth?: {
      is_login?: boolean
      is_vip?: boolean
      is_work?: boolean
      is_distribution?: boolean
    }
  }
  [key: string]: any
}

/**
 * 拉取当前登录态聚合（user/index）。
 * user 模块未装时路由表无 'user' key，route() 同步抛错，转为 MODULE_DISABLED 拒绝，
 * 由 restore() 的 .catch 静默保持空 flags（不清登录凭证、不弹提示）。
 */
function fetchUserIndex(): Promise<UserIndexResponse> {
  let url: string
  try {
    url = route('user')
  } catch (e) {
    return Promise.reject({ code: 'MODULE_DISABLED' })
  }
  return http.get<UserIndexResponse>(url)
}

export interface LoginPayload {
  token: string
  user_id: string | number
}

type AuthStore = AuthState & {
  hydrate(): void
  applyAuthFlags(flags: {
    is_login?: boolean
    is_vip?: boolean
    is_work?: boolean
    is_distribution?: boolean
  }): void
  restore(): Promise<void>
  ensureLogin(redirectUrl?: string): Promise<boolean>
  login(payload: LoginPayload): void
  logout(): void
}

/** user 模块是否启用（未 hydrate 时 features.user 为 undefined，按"未禁用"处理，让首屏照常尝试） */
function userModuleEnabled(): boolean {
  return commonStore.features.user !== false
}

export const authStore: AuthStore = observable<AuthStore>({
  api_token: '',
  user_id: '',
  loginEd: false,
  is_login: false,
  is_vip: false,
  is_work: false,
  is_distribution: false,

  hydrate: action(function (this: AuthStore) {
    this.api_token = wx.getStorageSync('api_token') || ''
    this.user_id = String(wx.getStorageSync('user_id') || '')
    this.loginEd = !!wx.getStorageSync('loginEd')
  }),

  applyAuthFlags: action(function (this: AuthStore, flags) {
    this.is_login = !!flags.is_login
    this.is_vip = !!flags.is_vip
    this.is_work = !!flags.is_work
    this.is_distribution = !!flags.is_distribution
  }),

  restore: function () {
    if (!userModuleEnabled()) {
      runInAction(function () {
        authStore.applyAuthFlags({})
      })
      return Promise.resolve()
    }
    return fetchUserIndex()
      .then((data) => {
        const auth = data && data.dou && data.dou.auth ? data.dou.auth : {}
        runInAction(function () {
          authStore.applyAuthFlags(auth)
        })
      })
      .catch((err: { code?: string }) => {
        runInAction(function () {
          // 仅会话真过期（UNAUTHORIZED）时清空 is_login（app.ts 拦截器实际已 logout，此处幂等）
          // 其它错误（NETWORK_ERROR / HTTP_5xx / NOT_FOUND）保留当前登录态，避免弱网假登出
          // 把 78 处 ensureLogin() 弹登录页的 UX 抖动堵在源头
          if (err && err.code === 'UNAUTHORIZED') {
            authStore.applyAuthFlags({})
          }
        })
      })
  },

  ensureLogin: function (redirectUrl?: string) {
    return authStore.restore().then(() => {
      if (!authStore.is_login) {
        wx.redirectTo({ url: redirectUrl || DEFAULT_LOGIN_REDIRECT })
        return false
      }
      return true
    })
  },

  login: action(function (this: AuthStore, payload: LoginPayload) {
    const apiToken = payload.token || ''
    const userId = String(payload.user_id || '')

    wx.setStorageSync('api_token', apiToken)
    wx.setStorageSync('user_id', userId)
    wx.setStorageSync('loginEd', true)
    clearPromotionUserSn()

    this.api_token = apiToken
    this.user_id = userId
    this.loginEd = true
    this.is_login = true
  }),

  logout: action(function (this: AuthStore) {
    wx.removeStorageSync('api_token')
    wx.removeStorageSync('user_id')
    wx.removeStorageSync('loginEd')

    this.api_token = ''
    this.user_id = ''
    this.loginEd = false
    this.is_login = false
    this.is_vip = false
    this.is_work = false
    this.is_distribution = false
  }),
})
