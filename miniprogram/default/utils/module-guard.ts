/**
 * 模块化兼容性查询工具（无副作用，不做跳转）。
 *
 * features 单一真相源是 commonStore.features（来自 bootstrap/index 的 features 字段）。
 * 不做路由级守卫 / 专用降级页：未装模块的 page 不在发布版 app.json，由微信 onPageNotFound 系统接管。
 */

import type { Features } from '../types/api'
import { commonStore } from '../stores/common.js'

/**
 * 强依赖 user 模块的衍生模块清单 —— 与 PHP config/system.php 的 link_user_center 同步。
 * 这些模块本身启用还不够，必须 user 模块也在才真正可用。
 */
export const USER_DERIVED_FEATURES = [
  'book',
  'aftersale',
  'health',
  'vip',
  'point',
  'money',
  'withdraw',
  'share',
  'comment',
  'favorites',
  'coupon',
  'distribution',
  'chat',
] as const

export type UserDerivedFeature = (typeof USER_DERIVED_FEATURES)[number]

/** 模块是否启用（核心模块恒 true，可选模块按后端 features 下发） */
export function isModuleEnabled(featureKey: keyof Features): boolean {
  return commonStore.features[featureKey] === true
}

/** 衍生模块是否真正可用（user 模块 + 衍生模块本身都启用，对应 PHP Module::requiresUser） */
export function isUserDerivedModuleEnabled(featureKey: UserDerivedFeature): boolean {
  return commonStore.features.user === true && commonStore.features[featureKey] === true
}
