/**
 * 运行环境 / 调试开关探测。
 *
 * - isDebugEnv()：当前是否「非正式版」（develop/trial 为 true，release 恒 false）。
 *   统一收口所有调试 UI；正式版自动静默。
 * - isServerDebug()：服务端调试开关（镜像 site.ts 的 debug_enable，由 admin -> miniprogram/sync 写入）。
 */

import { debug_enable } from '../config/site.js'

/** 当前是否处于非正式版调试环境（develop / trial 为真，release 恒假） */
export function isDebugEnv(): boolean {
  let env = ''
  try {
    env = wx.getAccountInfoSync().miniProgram.envVersion || ''
  } catch (e) {
    try {
      // 低版本基础库无 getAccountInfoSync 时回退读全局 __wxConfig
      const cfg = (typeof __wxConfig !== 'undefined' ? __wxConfig : undefined) as
        | { envVersion?: string }
        | undefined
      env = (cfg && cfg.envVersion) || ''
    } catch (e2) {
      env = ''
    }
  }
  return env !== '' && env !== 'release'
}

/** 服务端是否处于调试模式（镜像 site.ts debug_enable） */
export function isServerDebug(): boolean {
  try {
    return !!debug_enable
  } catch (e) {
    return false
  }
}
