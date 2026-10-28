/**
 * 报价单结算入口：签发报价单 → 存本地 → 跳结算页。
 *
 * 立即购买 / 积分 / 套餐共用。token 存 Storage 而非 URL，
 * 因为新增/编辑地址页会 redirectTo 回结算页，URL 参数会丢失。
 */

import { http } from '../services/http.js'
import { route } from './route.js'

export interface QuotePayload {
  module: string
  item_id: number | string
  item_number?: number | string
  mode?: string
  [key: string]: any
}

export interface QuoteResponse {
  quote_token: string
  mode: string
  cart: Record<string, any>
}

/** 清空本地报价单（下单成功或用户重新选择商品时调用） */
export function clearQuoteCache(): void {
  wx.removeStorageSync('buynow_quote')
}

/**
 * 条件清空：仅当缓存中的 token 与传入一致时清除，
 * 避免结算页报错时误删其它页面刚签发的报价单。
 */
export function clearQuoteToken(token: string): void {
  const cache = wx.getStorageSync('buynow_quote')
  if (cache && cache.token && String(cache.token) === String(token)) {
    clearQuoteCache()
  }
}

/** 读取未过期的本地报价单 token（30 分钟与服务端 TTL 对齐） */
export function readQuoteToken(): string {
  const cache = wx.getStorageSync('buynow_quote')
  if (!cache || !cache.token) {
    return ''
  }
  if (cache.created_at && Date.now() - cache.created_at > 30 * 60 * 1000) {
    clearQuoteCache()
    return ''
  }
  return String(cache.token)
}

/** 读取本地报价单的 mode（point/money） */
export function readQuoteMode(): string {
  const cache = wx.getStorageSync('buynow_quote')
  return cache && cache.mode ? String(cache.mode) : 'money'
}

let quoting: Promise<QuoteResponse> | null = null

/** 签发报价单并跳转结算页；同一时刻只允许一个在途请求（防双击） */
export function startQuoteCheckout(payload: QuotePayload): Promise<QuoteResponse> {
  if (quoting) {
    return quoting
  }

  quoting = http.post<QuoteResponse>(route('order.buynow'), payload).then(
    function (data) {
      wx.setStorageSync('buynow_quote', {
        token: data.quote_token,
        mode: data.mode,
        cart: data.cart,
        created_at: Date.now(),
      })
      quoting = null
      wx.navigateTo({ url: '/pages/order/checkout?source=quote' })
      return data
    },
    function (err) {
      quoting = null
      throw err
    }
  )

  return quoting
}