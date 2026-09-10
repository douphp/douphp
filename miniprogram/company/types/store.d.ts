/**
 * MobX store 公共形态声明（commonStore / authStore / cartStore）。
 *
 * 这些接口被 stores/*.ts 实现，也被页面 createStoreBindings 的 fields 引用，
 * 确保「store 字段名 <-> wxml 绑定名」单一真相源。
 */

import type {
  Features,
  LangPack,
  NavItem,
  SiteConfig,
  SiteParam,
} from './api'

/** 公共数据 store（对应 bootstrap/index 信封 + features 暴露） */
export interface CommonState {
  site: SiteConfig
  lang: LangPack
  param: SiteParam
  nav_list: NavItem[]
  /** 模块开关（核心 + 可选合并） */
  features: Features
  data: Record<string, any>
  user_level_has_data: boolean
  /** 缓存校验版本（后端 version 字段；缺省为空串） */
  version: string
  /** 是否已完成首屏 hydrate（页面可据此渲染骨架） */
  ready: boolean
}

/** 登录态 store */
export interface AuthState {
  /** API 会话 token（登录签发，经 Authorization: Bearer 请求头回传） */
  api_token: string
  /** 仅作展示用途，不参与鉴权 */
  user_id: string
  /** 登录动作完成标记（对应 storage loginEd） */
  loginEd: boolean
  is_login: boolean
  is_vip: boolean
  is_work: boolean
  is_distribution: boolean
}

/** 购物车 store */
export interface CartState {
  number: number
}
