/**
 * 页面导航标题取值 —— 取代后台生成的 navigation_bar_title.ts。
 *
 * 键名沿用旧生成器命名规则（module / module_subpath，如 product、book_work），
 * 取值走 commonStore.lang（route=lang 全量译串包；冷启动由 config/seed.generated.ts 种子兜底）。
 *   - 'site_name' 特例：取 commonStore.site.site_name
 *   - 译串无键或为空串：返回 ''（与旧生成器空标题行为一致）
 *
 * 详情页若 API 返回 data.title，则以 API 为准，不调用本函数。
 */

import { commonStore } from '../stores/common.js'

export function pageTitle(key: string): string {
  if (key === 'site_name') {
    return (commonStore.site && (commonStore.site.site_name as string)) || ''
  }
  const bag = commonStore.lang || {}
  const v = bag[key]
  return v != null && v !== '' ? v : ''
}
