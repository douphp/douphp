/**
 * 应用引导服务 —— 对应 PHP api/service/bootstrap/BootstrapService。
 */

import type { BootstrapData } from '../types/api'
import { http } from './http.js'
import { route } from '../utils/route.js'

/** 拉取应用引导数据（bootstrap/index）：site/param/data/features/nav_list/lang_v/version */
export function fetchBootstrap(): Promise<BootstrapData> {
  return http.get<BootstrapData>(route('bootstrap'))
}
