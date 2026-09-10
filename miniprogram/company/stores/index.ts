/**
 * store 聚合 + 应用启动引导。
 */

import { autorun } from '../libs/mobx-miniprogram/index.js'
import { resolveTabIndex } from '../utils/tabbar.js'
import { authStore } from './auth.js'
import { cartStore } from './cart.js'
import { commonStore } from './common.js'

export { commonStore } from './common.js'
export { authStore } from './auth.js'
export { cartStore } from './cart.js'

/** 购物车业务页固定路径（DouPHP 产品约定，不随模块启停变化；未装 order 模块时不在 tabBar） */
const CART_TAB_PAGE_PATH = 'pages/order/order'

let cartBadgeBound = false

/** tabBar 购物车角标统一由 cartStore.badge 驱动（取代散落的 setTabBarBadge 调用） */
function bindCartBadge(): void {
  if (cartBadgeBound) {
    return
  }
  cartBadgeBound = true
  autorun(function () {
    const text = cartStore.badge
    const index = resolveTabIndex(CART_TAB_PAGE_PATH)
    if (index < 0) {
      return
    }
    try {
      if (text) {
        wx.setTabBarBadge({ index, text })
      } else {
        wx.removeTabBarBadge({ index })
      }
    } catch (e) {
      // 非 tabBar 页面调用会失败，静默忽略
    }
  })
}

/**
 * app.onLaunch 调用：
 *   1. 先 hydrate（storage 秒开，0ms 冷启动）
 *   2. 再 refresh / restore（网络刷新；失败保留 hydrate 数据）
 *   3. cartStore 预热（features.order=false 或未登录时自动短路）
 */
export function bootstrapStores(): void {
  commonStore.hydrate()
  authStore.hydrate()
  bindCartBadge()

  commonStore.refresh().then(function () {
    // common 刷新拿到最新 features 后，再决定 auth / cart 是否降级
    authStore.restore()
    cartStore.refresh()
  })
}
