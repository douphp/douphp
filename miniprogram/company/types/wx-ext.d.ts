/**
 * 对微信官方 api-typings 的本地扩展。
 *
 * 1. 给 Page / Component 实例补 storeBindings 字段（createStoreBindings 返回值挂在 this 上，
 *    onUnload / detached 时 destroyStoreBindings）。
 * 2. 放开实例上的 ad-hoc 字段（[key: string]: any）—— 历史 113 页大量在 this 上挂临时字段
 *    （定时器句柄、缓存对象等）；放开后这些访问为 any，而 data / setData / 已知方法仍强类型。
 */

import type { StoreBindings } from '../libs/mobx-miniprogram-bindings/index.js'

declare global {
  namespace WechatMiniprogram.Page {
    interface InstanceProperties {
      /** createStoreBindings 返回值；onUnload 调 destroyStoreBindings */
      storeBindings?: StoreBindings
      [key: string]: any
    }
  }

  namespace WechatMiniprogram.Component {
    interface InstanceProperties {
      storeBindings?: StoreBindings
      [key: string]: any
    }
  }

  /**
   * 基础库注入的全局配置对象（app.json 编译产物）。
   *
   * api-typings 未声明；本地按已知用法收口：
   *   - envVersion：低版本基础库 fallback 读取小程序运行环境（release / trial / develop）
   *   - tabBar.list：运行时 tabBar 真实形态（按发布版 app.json 裁剪后的快照）
   *     —— pagePath 实测会带渲染层后缀（3.14.1 是 .html），调用方需 normalize
   */
  const __wxConfig: {
    envVersion?: string
    tabBar?: {
      list?: Array<{ pagePath?: string }>
    }
  } | undefined

  /** App 实例形态（app.ts 注入），getApp<IAppOption>() 取用 */
  interface IAppOption {
    /** 站点根地址（site.ts root_url） */
    root_url: string
    /** API 前缀（site.ts mp_url，douUrl 拼接基础） */
    mp_url: string
    /** 全局 loading 开关（site.ts douLoading） */
    douLoading: boolean
    globalData: {
      statusBarHeight: number | string
      windowHeight: number | string
      menuButtonHeight: number
      navigationBarHeight: number
      navigationBarAndStatusBarHeight?: number
      [key: string]: any
    }
    [key: string]: any
  }
}

export {}
