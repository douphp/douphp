/**
 * store 持久化 —— localStorage 水合 / 写回 + schema_version 失效。
 *
 * 写入结构：{ __v: SCHEMA_VERSION, data }；读取时 __v 不匹配直接丢弃（升级即失效旧缓存）。
 */

import { autorun, toJS } from '../libs/mobx-miniprogram/index.js'

/** 缓存结构版本：字段不兼容变更时 +1，旧缓存自动失效 */
export const SCHEMA_VERSION = 1

const PREFIX = 'store:'

interface Envelope<T> {
  __v: number
  data: T
}

/** 读取持久化数据；不存在 / 版本不匹配 / 解析失败均返回 null */
export function loadPersisted<T>(key: string): T | null {
  try {
    const raw = wx.getStorageSync(PREFIX + key)
    if (!raw) {
      return null
    }
    const parsed: Envelope<T> = typeof raw === 'string' ? JSON.parse(raw) : raw
    if (!parsed || parsed.__v !== SCHEMA_VERSION) {
      return null
    }
    return parsed.data
  } catch (e) {
    return null
  }
}

/** 写回持久化数据（带 schema 版本） */
export function savePersisted<T>(key: string, data: T): void {
  try {
    const envelope: Envelope<T> = { __v: SCHEMA_VERSION, data }
    wx.setStorageSync(PREFIX + key, JSON.stringify(envelope))
  } catch (e) {
    /* storage 写入失败静默忽略 */
  }
}

export function clearPersisted(key: string): void {
  try {
    wx.removeStorageSync(PREFIX + key)
  } catch (e) {
    /* 静默 */
  }
}

/** 用 autorun 订阅 selector，变化即写回 storage；返回 disposer */
export function persistAutorun<T>(key: string, selector: () => T): () => void {
  return autorun(function () {
    savePersisted(key, toJS(selector()))
  })
}
