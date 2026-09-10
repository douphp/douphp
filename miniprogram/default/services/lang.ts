/**
 * 语言包服务 —— 对应 PHP api route=lang（LangController::index）。
 *
 * 与 bootstrap 拆分：bootstrap 仅下发 lang_v 指纹，本接口返回译串全表（lang_all()），
 * 由 commonStore 合并入 lang。
 */

import type { LangPack } from '../types/api'
import { http } from './http.js'
import { route } from '../utils/route.js'

/** 拉取语言包译串全表（route=lang） */
export function fetchLang(): Promise<LangPack> {
  return http.get<LangPack>(route('lang'))
}
