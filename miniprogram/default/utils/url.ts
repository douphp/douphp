/**
 * @deprecated 历史 URL 字面拼接 helper —— 业务调用请改用 utils/route.ts 的 route(name, params)。
 *
 * 仅保留作为「调用了未声明 API 路由」场景的逃生口：
 *   - pages/product/add.ts / pages/product/edit.ts 中的 attribute/add|del|image 端点
 *     在 api/route/attribute.php 里并无对应 Route::* 声明（AttributeController 仅有 index），
 *     这些调用面历史上即为 404，与本次命名路由迁移无关。
 *
 * 一旦后端补齐 attribute 路由（或对应表单功能下线），即可把 add.ts / edit.ts 切到 route() 并删除本文件。
 *
 * mp_url / rewrite_enable 直接从 config/site.ts 取（admin sync 单一真相源）；
 * 不走 getApp().mp_url —— App() 注册期（onLaunch 内 bootstrapStores -> commonStore.refresh -> douUrl）
 * 调 getApp() 返回 undefined，会触发 "Cannot read property 'mp_url' of undefined"。
 */

import { mp_url, rewrite_enable } from '../config/site.js'

type UrlArg = string | number | Record<string, any>

export function douUrl(module: string, ...args: UrlArg[]): string {
  const pathParts: string[] = []
  let queryParams: Record<string, any> = {}

  for (let i = 0; i < args.length; i++) {
    const arg = args[i]
    if (i === args.length - 1 && typeof arg === 'object' && !Array.isArray(arg)) {
      queryParams = arg
    } else if (typeof arg === 'string' || typeof arg === 'number') {
      pathParts.push(encodeURIComponent(String(arg)))
    }
  }

  let route = module
  if (pathParts.length > 0) {
    route += '/' + pathParts.join('/')
  }

  const query: string[] = []
  const keys = Object.keys(queryParams)
  for (let j = 0; j < keys.length; j++) {
    const key = keys[j]
    const val = queryParams[key]
    if (val !== undefined && val !== null && val !== '') {
      query.push(key + '=' + encodeURIComponent(val))
    }
  }

  let url = rewrite_enable
    ? mp_url + route
    : mp_url + 'index.php?route=' + route
  if (query.length > 0) {
    url += (url.indexOf('?') >= 0 ? '&' : '?') + query.join('&')
  }
  return url
}
