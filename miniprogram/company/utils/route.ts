/**
 * 命名路由取址 —— 与 PHP route() / front route() 同心智。
 *
 *   route('article.show', { id: 12 })   -> {mp_url}article/12（rewrite_enable）
 *   route('book.user.show', { id: 3 })  -> ...user/book/3
 *   route('coupon.user')                -> ...user/coupon
 *
 * rewrite_enable 关闭时回退 index.php?route= 形态，与 UrlGenerator::buildBackendUrl 一致。
 *
 * 路由表 routes.generated.ts 由后台 admin/index.php?route=miniprogram/sync 生成（key 已去 api. 前缀）。
 * 后端 URL 物理形态变更只需点一次后台同步，调用面零改动。
 *
 * mp_url / rewrite_enable 直接从 config/site.ts 取（admin sync 单一真相源），不走 getApp().mp_url（App 注册期为 undefined）。
 */

import { mp_url, rewrite_enable } from '../config/site.js'
import { routes } from './routes.generated.js'

type RouteParams = Record<string, string | number | undefined>

export function route(name: string, params?: RouteParams): string {
  const table = routes as Record<string, string | undefined>
  let path = table[name]
  if (path === undefined) {
    path = table['api.' + name]
  }
  if (path === undefined) {
    throw new Error('Unknown route: ' + name)
  }

  const query: string[] = []
  if (params) {
    const keys = Object.keys(params)
    for (let i = 0; i < keys.length; i++) {
      const key = keys[i]
      const raw = params[key]
      if (raw === undefined || raw === null) {
        continue
      }
      const val = String(raw)
      if (val === '' || val === 'undefined' || val === 'null') {
        continue
      }
      const placeholder = '{' + key + '}'
      if (path.indexOf(placeholder) >= 0) {
        path = path.replace(placeholder, encodeURIComponent(val))
      } else {
        query.push(key + '=' + encodeURIComponent(val))
      }
    }
  }

  // 占位符未被填充（对应入参缺失，与 douUrl 丢弃 undefined 段同义）：剔除残留 {x} 段。
  path = path.replace(/\/\{[a-zA-Z_][a-zA-Z0-9_]*\}/g, '')
  path = path.replace(/\{[a-zA-Z_][a-zA-Z0-9_]*\}/g, '')
  path = path.replace(/\/{2,}/g, '/').replace(/\/+$/, '')

  let url = rewrite_enable
    ? mp_url + path
    : mp_url + 'index.php?route=' + path
  if (query.length > 0) {
    url += (url.indexOf('?') >= 0 ? '&' : '?') + query.join('&')
  }
  return url
}
