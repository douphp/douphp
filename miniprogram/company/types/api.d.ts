/**
 * DouPHP 小程序端 <-> PHP 后端 API 契约。
 *
 * 与后端真实信封 / 字段对齐：
 * - HTTP 信封：api/...（code/message/data/errors/request_id），见 services/http.ts
 * - 应用引导：api/service/bootstrap/BootstrapService::build()
 *
 * 注意：本文件是 .d.ts，只能声明类型，不能含运行时值。
 * 运行时常量（如 USER_DERIVED_FEATURES 数组）放在 utils/module-guard.ts。
 */

/* ============================ HTTP 信封 ============================ */

/** 业务响应码：成功固定 'OK'，失败为各类语义码或 HTTP_<status> */
export type ApiCode =
  | 'OK'
  | 'INVALID_PARAMS'
  | 'UNAUTHORIZED'
  | 'FORBIDDEN'
  | 'NOT_FOUND'
  | 'BUSINESS_RULE_VIOLATION'
  | 'NETWORK_ERROR'
  | 'SERVER_ERROR'
  | string

export interface ApiEnvelope<T = unknown> {
  code: ApiCode
  message: string
  data: T
  errors: Record<string, string>
  request_id: string
}

/* ============================ 模块开关（强类型） ============================ */

/**
 * 必装核心模块：永远存在（非可选 boolean）。
 * 对应 config/system.php 中不可卸载的核心：product / article / data。
 */
export interface CoreFeatures {
  product: boolean
  article: boolean
  data: boolean
}

/**
 * 可选模块：boolean | undefined（未安装时后端不下发该键，TS 强制处理 undefined）。
 * 键名与 config/system.php 的 single_module / column_module 对齐；
 * 末尾 index 签名兜底其余动态模块键。
 */
export interface OptionalFeatures {
  user?: boolean
  order?: boolean
  book?: boolean
  vip?: boolean
  point?: boolean
  money?: boolean
  withdraw?: boolean
  coupon?: boolean
  favorites?: boolean
  distribution?: boolean
  comment?: boolean
  aftersale?: boolean
  health?: boolean
  share?: boolean
  ai?: boolean
  vote?: boolean
  form?: boolean
  guestbook?: boolean
  consultation?: boolean
  chat?: boolean
  sn?: boolean
  store?: boolean
  job?: boolean
  faq?: boolean
  doc?: boolean
  professional?: boolean
  solution?: boolean
  support?: boolean
  course?: boolean
  download?: boolean
  gallery?: boolean
  cases?: boolean
  video?: boolean
  item?: boolean
  service?: boolean
  certificate?: boolean
  link?: boolean
  onepic?: boolean
  partner?: boolean
  equipment?: boolean
  team?: boolean
  tag?: boolean
  brand?: boolean
  area?: boolean
  landing?: boolean
  dh?: boolean
  work?: boolean
  sms?: boolean
  [key: string]: boolean | undefined
}

/** 完整模块开关：核心必装 + 可选 */
export type Features = CoreFeatures & OptionalFeatures

/* ============================ 应用引导 ============================ */

/** 站点配置（Config::get('site')）——字段动态，提供常用键 + index 兜底 */
export interface SiteConfig {
  name?: string
  url?: string
  logo?: string
  tel?: string
  mobile?: string
  address?: string
  email?: string
  icp?: string
  copyright?: string
  [key: string]: string | undefined
}

/** 站点参数（Config::get('param')） */
export type SiteParam = Record<string, any>

/** 多语言串包（lang_all()） */
export type LangPack = Record<string, string>

/** 导航项（MiniprogramNavigationBuilder::build('miniprogram_top')） */
export interface NavItem {
  url: string
  name: string
  status?: { value: string; [key: string]: any }
  [key: string]: any
}

/** bootstrap/index 完整响应（BootstrapService::build() 返回结构） */
export interface BootstrapData {
  site: SiteConfig
  param: SiteParam
  data: Record<string, any>
  /** 模块开关（核心 + 可选合并），决定小程序端 wx:if 路径 */
  features: Features
  user_level_has_data: boolean
  nav_list: NavItem[]
  /** 语言包内容指纹：bootstrap 下发，客户端据其变化决定是否重新拉取 route=lang */
  lang_v?: string
  /**
   * 语言包译串全表：bootstrap 已不再内嵌，由 route=lang 单独拉取后在 commonStore.refresh()
   * 合并入本字段并随整体落盘（offline-first），故为运行时 merge 字段。
   */
  lang?: LangPack
  /** 缓存失效校验（php-version 阶段由后端补；老后端可能缺省） */
  version?: string
}

/** order/cart_number 响应 */
export interface CartNumberData {
  cart_number: number
}
