/**
 * 统一 HTTP 层 —— DouPHP API 信封（code/message/data/errors/request_id）。
 *
 * 职责：
 *   - 信封解析：code === 'OK' 为成功，resolve(parsed.data<T>)；否则 reject(ApiError)
 *   - 默认头注入：Content-Type=application/x-www-form-urlencoded、Authorization: Bearer <api_token>
 *   - 拦截器：onRequest（改 config）/ onSuccess（成功旁路）/ onError（失败旁路，如 UNAUTHORIZED -> logout）
 *   - dedupe：相同 GET 在飞行中复用同一 Promise
 *   - cache + ttl：opts.cache 命中且未过期直接返回；opts.revalidate 强制走网络刷新
 *   - 调试钩子：request_id 装饰、Console / vConsole 打点、服务端异常 modal（仅 isDebugEnv）
 *
 * 业务页面只调 http.get / http.post，永远拿到 data<T> 或捕获 ApiError。
 */

import type { ApiCode, ApiEnvelope } from '../types/api'
import { isDebugEnv } from '../utils/env.js'

/** 单次请求配置 */
export interface RequestConfig {
  url: string
  data?: Record<string, any>
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE'
  header?: Record<string, string>
  responseType?: 'text' | 'arraybuffer'
}

/** 请求附加选项（缓存 / 去重） */
export interface RequestOpts {
  /** 命中内存缓存直接返回（GET 推荐） */
  cache?: boolean
  /** 缓存有效期（毫秒），默认 60_000 */
  ttl?: number
  /** 即便有缓存也强制走网络（SWR 刷新场景） */
  revalidate?: boolean
  /** 相同 GET 在飞行中是否复用同一 Promise，默认 true */
  dedupe?: boolean
}

/** 标准错误对象（信封解析失败 / 业务码非 OK / 网络异常 / HTTP 非 2xx 统一为此） */
export class ApiError extends Error {
  code: ApiCode
  statusCode: number
  errors: Record<string, string>
  data: Record<string, any>
  request_id: string

  constructor(fields: {
    code: ApiCode
    message: string
    statusCode: number
    errors?: Record<string, string>
    data?: Record<string, any>
    request_id?: string
  }) {
    super(fields.message || '请求失败')
    this.name = 'ApiError'
    this.code = fields.code
    this.statusCode = fields.statusCode
    this.errors = fields.errors || {}
    this.data = fields.data || {}
    this.request_id = fields.request_id || ''
    // 修正 ES5 下 extends Error 的原型链，保证 instanceof / 字段读取稳定
    Object.setPrototypeOf(this, ApiError.prototype)
  }
}

interface ParsedEnvelope {
  ok: boolean
  code: string
  message: string
  data: Record<string, any>
  errors: Record<string, string>
  request_id: string
  raw: Record<string, any>
}

type RequestInterceptor = (config: RequestConfig) => RequestConfig | void
type SuccessInterceptor = (data: any, ctx: { config: RequestConfig; request_id: string }) => void
type ErrorInterceptor = (err: ApiError, ctx: { config: RequestConfig }) => void

const requestInterceptors: RequestInterceptor[] = []
const successInterceptors: SuccessInterceptor[] = []
const errorInterceptors: ErrorInterceptor[] = []

// 最近一次接口响应的 request_id（成功 / 失败都更新），供调试信息页 trace
let lastRequestId = ''

/** 读取最近一次接口响应的 request_id（缺失时为空串） */
export function getLastRequestId(): string {
  return lastRequestId
}

interface CacheEntry {
  data: any
  expire: number
}
const memoryCache: Record<string, CacheEntry | undefined> = {}
const inflight: Record<string, Promise<any> | undefined> = {}

/** 严格解析标准响应体（仅读 code/message/data/errors/request_id，不兼容历史字段） */
function parseEnvelope(body: unknown): ParsedEnvelope {
  let obj: any = body
  if (typeof obj === 'string') {
    let trimmed = obj.trim()
    const jsonStart = trimmed.indexOf('{')
    if (jsonStart > 0) {
      trimmed = trimmed.substring(jsonStart)
    }
    try {
      obj = JSON.parse(trimmed)
    } catch (e) {
      obj = {}
    }
  }
  const raw: Record<string, any> = obj && typeof obj === 'object' ? obj : {}
  const code = typeof raw.code === 'string' ? raw.code : ''
  const message = typeof raw.message === 'string' ? raw.message : ''
  const errors = raw.errors && typeof raw.errors === 'object' ? raw.errors : {}
  const data = raw.data && typeof raw.data === 'object' ? raw.data : {}
  const requestId = typeof raw.request_id === 'string' ? raw.request_id : ''
  const ok = code === 'OK'
  return {
    ok,
    code: code || (ok ? 'OK' : 'UNKNOWN_ERROR'),
    message,
    data,
    errors,
    request_id: requestId,
    raw,
  }
}

/** 调试期把追踪号尾 6 位拼到 message 末尾，便于和服务端日志对照 */
function decorateErrorForDebug(err: ApiError): ApiError {
  if (err.request_id) {
    lastRequestId = err.request_id
  }
  if (isDebugEnv() && err.request_id) {
    err.message = (err.message || '') + ' [req:' + String(err.request_id).slice(-6) + ']'
  }
  return err
}

function debugLogError(config: RequestConfig, err: ApiError): void {
  if (isDebugEnv()) {
    console.error('[API]', config.method || 'GET', config.url, err)
  }
}

/** 调试期且服务端返回未捕获异常调试载荷（errors.exception）时主动弹 modal 展示堆栈 */
function maybeShowServerDebugModal(err: ApiError): void {
  if (!isDebugEnv()) {
    return
  }
  const dbg: any = err && err.errors
  if (!dbg || !dbg.exception || !dbg.file) {
    return
  }
  const content =
    (err.message || '') + '\n\n' +
    dbg.exception + '\n' +
    dbg.file + ':' + dbg.line + '\n\n' +
    String(dbg.trace || '').slice(0, 800)
  wx.showModal({
    title: '[DEV] ' + (err.code || 'SERVER_ERROR'),
    content,
    showCancel: false,
    confirmText: '复制',
    success(r) {
      if (r.confirm) {
        wx.setClipboardData({ data: JSON.stringify(err, null, 2) })
      }
    },
  })
}

function runErrorInterceptors(err: ApiError, config: RequestConfig): void {
  decorateErrorForDebug(err)
  debugLogError(config, err)
  maybeShowServerDebugModal(err)
  for (let i = 0; i < errorInterceptors.length; i++) {
    try {
      errorInterceptors[i](err, { config })
    } catch (e) {
      /* 拦截器自身异常不应影响主流程 */
    }
  }
}

function cacheKey(config: RequestConfig): string {
  return (config.method || 'GET') + ' ' + config.url + ' ' + JSON.stringify(config.data || {})
}

/** 核心请求：解析信封，成功 resolve data<T>，失败 reject ApiError */
export function request<T = any>(config: RequestConfig, opts?: RequestOpts): Promise<T> {
  let cfg: RequestConfig = {
    url: config.url,
    data: config.data || {},
    method: config.method || 'GET',
    responseType: config.responseType,
    header: Object.assign(
      {
        'Content-Type': 'application/x-www-form-urlencoded',
        Authorization: 'Bearer ' + (wx.getStorageSync('api_token') || ''),
      },
      config.header || {}
    ),
  }

  for (let i = 0; i < requestInterceptors.length; i++) {
    const out = requestInterceptors[i](cfg)
    if (out) {
      cfg = out
    }
  }

  const options = opts || {}
  const isGet = (cfg.method || 'GET') === 'GET'
  const key = cacheKey(cfg)
  const ttl = typeof options.ttl === 'number' ? options.ttl : 60000

  if (options.cache && !options.revalidate) {
    const hit = memoryCache[key]
    if (hit && hit.expire > Date.now()) {
      return Promise.resolve(hit.data as T)
    }
  }

  const dedupe = options.dedupe !== false
  if (isGet && dedupe && inflight[key]) {
    return inflight[key] as Promise<T>
  }

  const p = new Promise<T>(function (resolve, reject) {
    wx.request({
      url: cfg.url,
      data: cfg.data || {},
      method: cfg.method || 'GET',
      responseType: cfg.responseType,
      header: cfg.header,
      success(res) {
        const body = res && res.data
        const status = (res && res.statusCode) || 0

        if (status < 200 || status >= 300) {
          const parsedHttp = parseEnvelope(body)
          const httpCode =
            parsedHttp.code && parsedHttp.code !== 'UNKNOWN_ERROR'
              ? parsedHttp.code
              : 'HTTP_' + status
          const httpErr = new ApiError({
            code: httpCode,
            message: parsedHttp.message || '请求失败',
            errors: parsedHttp.errors,
            statusCode: status,
            data: parsedHttp.data,
            request_id: parsedHttp.request_id,
          })
          runErrorInterceptors(httpErr, cfg)
          reject(httpErr)
          return
        }

        const parsed = parseEnvelope(body)
        if (!parsed.ok) {
          const bizErr = new ApiError({
            code: parsed.code,
            message: parsed.message || '请求失败',
            errors: parsed.errors,
            statusCode: status,
            data: parsed.data,
            request_id: parsed.request_id,
          })
          runErrorInterceptors(bizErr, cfg)
          reject(bizErr)
          return
        }

        if (parsed.request_id) {
          lastRequestId = parsed.request_id
        }

        const successData: Record<string, any> = parsed.data || {}
        // 成功 data 上附只读、不可枚举元信息，便于业务读取 message / request_id；
        // 不可枚举保证 setData / JSON.stringify 不会序列化它们。
        try {
          Object.defineProperty(successData, '__message', {
            value: parsed.message || '',
            enumerable: false,
            writable: true,
            configurable: true,
          })
          Object.defineProperty(successData, '__request_id', {
            value: parsed.request_id || '',
            enumerable: false,
            writable: true,
            configurable: true,
          })
        } catch (e) {
          /* data 冻结或异常环境时静默忽略 */
        }

        if (options.cache) {
          memoryCache[key] = { data: successData, expire: Date.now() + ttl }
        }

        for (let i = 0; i < successInterceptors.length; i++) {
          try {
            successInterceptors[i](successData, { config: cfg, request_id: parsed.request_id })
          } catch (e) {
            /* 拦截器异常不影响主流程 */
          }
        }

        resolve(successData as T)
      },
      fail(err) {
        const netErr = new ApiError({
          code: 'NETWORK_ERROR',
          message: (err && err.errMsg) || '网络异常',
          statusCode: 0,
        })
        runErrorInterceptors(netErr, cfg)
        reject(netErr)
      },
      complete() {
        if (isGet) {
          delete inflight[key]
        }
      },
    })
  })

  if (isGet && dedupe) {
    inflight[key] = p
  }

  return p
}

export function get<T = any>(url: string, data?: Record<string, any>, opts?: RequestOpts): Promise<T> {
  return request<T>({ url, data, method: 'GET' }, opts)
}

export function post<T = any>(url: string, data?: Record<string, any>, opts?: RequestOpts): Promise<T> {
  return request<T>({ url, data, method: 'POST' }, opts)
}

/**
 * RESTful 更新（PUT）。
 *
 * 微信 wx.request 原生 PUT 体不会被 PHP 填入 $_POST（仅 POST 自动解析 x-www-form-urlencoded），
 * 故经「方法伪装」承载：真实发 POST + body `_method=PUT`，由 core Request::method() 还原为 PUT。
 */
export function put<T = any>(url: string, data?: Record<string, any>, opts?: RequestOpts): Promise<T> {
  return request<T>({ url, data: Object.assign({}, data, { _method: 'PUT' }), method: 'POST' }, opts)
}

/**
 * RESTful 删除（DELETE），同 put 经 `_method=DELETE` 方法伪装承载。
 */
export function del<T = any>(url: string, data?: Record<string, any>, opts?: RequestOpts): Promise<T> {
  return request<T>({ url, data: Object.assign({}, data, { _method: 'DELETE' }), method: 'POST' }, opts)
}

/** 清空内存缓存（按 key 前缀或全部） */
export function clearCache(prefix?: string): void {
  if (!prefix) {
    for (const k in memoryCache) {
      delete memoryCache[k]
    }
    return
  }
  for (const k in memoryCache) {
    if (k.indexOf(prefix) >= 0) {
      delete memoryCache[k]
    }
  }
}

export const http = {
  request,
  get,
  post,
  put,
  del,
  clearCache,
  getLastRequestId,
  ApiError,
  /** 拦截器注册入口（app.ts onLaunch 调用） */
  onRequest(fn: RequestInterceptor): void {
    requestInterceptors.push(fn)
  },
  onSuccess(fn: SuccessInterceptor): void {
    successInterceptors.push(fn)
  },
  onError(fn: ErrorInterceptor): void {
    errorInterceptors.push(fn)
  },
}

export default http
