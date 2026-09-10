/**
 * 购物车 store —— 数量 + 角标（computed）。
 *
 * 模块降级：commonStore.features.order !== true 时 number 恒 0、refresh 短路不发请求。
 * 与 utils/module-guard.ts 同心智：可选模块正向 opt-in（=== true），未装时 features.order 为 undefined。
 */

import { action, observable, runInAction } from '../libs/mobx-miniprogram/index.js'
import type { CartNumberData } from '../types/api'
import type { CartState } from '../types/store'
import { http } from '../services/http.js'
import { route } from '../utils/route.js'
import { commonStore } from './common.js'

type CartStore = CartState & {
  readonly badge: string
  refresh(): Promise<void>
  reset(): void
}

function orderModuleEnabled(): boolean {
  return commonStore.features.order === true
}

/**
 * 购物车数量（order/cart_number）。
 * 可选模块兜底：未登录 / order 未装 / 后端异常时静默返回 0，不抛错、不弹提示。
 * order 未装时 route() 抛 Unknown route 为同步异常，不在 .catch 链内，需 try/catch 兜底。
 */
function fetchCartNumber(): Promise<number> {
  let url: string
  try {
    url = route('order.cart')
  } catch (e) {
    return Promise.resolve(0)
  }
  return http
    .get<CartNumberData>(url)
    .then((data) => (data && typeof data.cart_number === 'number' ? data.cart_number : 0))
    .catch(() => 0)
}

export const cartStore: CartStore = observable<CartStore>({
  number: 0,

  /** tabBar 角标文案：0 显示空串，>99 显示 '99+' */
  get badge(): string {
    if (this.number > 99) {
      return '99+'
    }
    return this.number > 0 ? String(this.number) : ''
  },

  refresh: function () {
    if (!orderModuleEnabled()) {
      runInAction(function () {
        cartStore.number = 0
      })
      return Promise.resolve()
    }
    if (!wx.getStorageSync('api_token')) {
      runInAction(function () {
        cartStore.number = 0
      })
      return Promise.resolve()
    }
    return fetchCartNumber().then((n) => {
      runInAction(function () {
        cartStore.number = n
      })
    })
  },

  reset: action(function (this: CartStore) {
    this.number = 0
  }),
})
