/**
 * 语言包译串读取（TS 侧）—— 供 wx.showModal / wx.showToast 等运行时取译串。
 *
 * 译串来自 commonStore.lang（route=lang 拉取并合并）。命中返回译串（含空串），
 * 未命中返回 fallback 或原 key。wxml 侧仍直接用 {{lang.xxx}} 绑定，不经本函数。
 */

import { commonStore } from '../stores/common.js'

export function lang(key: string, fallback?: string): string {
  const bag = commonStore.lang || {}
  if (Object.prototype.hasOwnProperty.call(bag, key)) {
    return bag[key]
  }
  return fallback != null ? fallback : key
}
