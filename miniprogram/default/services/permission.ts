/**
 * 工作台权限校验 —— 跨模块共用（product/order/user/health/aftersale 的 work 页）。
 *
 * work 模块未装时路由表无 'work.permission' key，route() 同步抛错，
 * 视为无权限降级 switchTab 回首页；后端校验失败同样回首页。
 */

import { http } from './http.js'
import { route } from '../utils/route.js'

/** 工作台权限校验：无权则 switchTab 回首页 */
export function checkWorkPermission(module: string): void {
  let url: string
  try {
    url = route('work.permission')
  } catch (e) {
    wx.switchTab({ url: '/pages/index/index' })
    return
  }
  http.post(url, { module }).catch(() => {
    wx.switchTab({ url: '/pages/index/index' })
  })
}
