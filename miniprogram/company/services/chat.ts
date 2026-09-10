/**
 * chat 模块服务层：流式对话（chunked SSE 文本协议）。
 *
 * 与 Web 端 theme/default/js/chat.js 同源协议：
 *   POST index.php?route=chat/stream  →  data: {"content": "..."}\n\n 流
 * 小程序以 wx.request enableChunked 接收，按行解析后逐段回调。
 */

import { route } from '../utils/route.js'

/** UTF-8 字节流解码（基础库 TextDecoder 不保证可用，自带实现兜底） */
function utf8Decode(bytes: Uint8Array): string {
  let out = ''
  let i = 0
  while (i < bytes.length) {
    const b1 = bytes[i++]
    if (b1 < 0x80) {
      out += String.fromCharCode(b1)
    } else if (b1 < 0xe0) {
      out += String.fromCharCode(((b1 & 0x1f) << 6) | (bytes[i++] & 0x3f))
    } else if (b1 < 0xf0) {
      out += String.fromCharCode(((b1 & 0x0f) << 12) | ((bytes[i++] & 0x3f) << 6) | (bytes[i++] & 0x3f))
    } else {
      const cp =
        ((b1 & 0x07) << 18) | ((bytes[i++] & 0x3f) << 12) | ((bytes[i++] & 0x3f) << 6) | (bytes[i++] & 0x3f)
      const offset = cp - 0x10000
      out += String.fromCharCode(0xd800 + (offset >> 10), 0xdc00 + (offset & 0x3ff))
    }
  }
  return out
}

export interface StreamCallbacks {
  /** 每收到一段增量文本 */
  onContent(text: string): void
  /** 服务端业务错误（流内 error 或非 200 包络） */
  onError(message: string): void
  /** 流正常结束 */
  onDone(): void
}

export interface StreamHandle {
  abort(): void
}

/**
 * 发起流式对话。
 *
 * @param payload prompt / session_sn / model_id
 * @param callbacks 增量回调
 */
export function chatStream(payload: Record<string, any>, callbacks: StreamCallbacks): StreamHandle {
  let buffer: number[] = []
  let textBuffer = ''
  let finished = false
  let gotChunk = false

  const finish = function (fn: () => void) {
    if (!finished) {
      finished = true
      fn()
    }
  }

  const handleLine = function (line: string) {
    if (line.indexOf('data: ') !== 0) {
      return
    }
    const data = line.substring(6)
    if (data === '[DONE]') {
      return
    }
    try {
      const json = JSON.parse(data)
      if (json.content) {
        callbacks.onContent(String(json.content))
      } else if (json.error) {
        finish(function () {
          callbacks.onError(String(json.error))
        })
      }
    } catch (e) {
      /* 不完整 JSON 行忽略 */
    }
  }

  const task = wx.request({
    url: route('chat.stream'),
    method: 'POST',
    enableChunked: true,
    responseType: 'arraybuffer',
    header: {
      'Content-Type': 'application/x-www-form-urlencoded',
      Authorization: 'Bearer ' + (wx.getStorageSync('api_token') || ''),
    },
    data: payload,
    success(res) {
      // 前置校验失败时后端走 ApiResponse 包络（非 200 一次性 JSON）
      if (!gotChunk && res && res.statusCode >= 400) {
        let message = '请求失败，请稍后重试'
        try {
          const body = typeof res.data === 'string' ? JSON.parse(res.data) : res.data
          if (body && (body as any).message) {
            message = String((body as any).message)
          }
        } catch (e) {
          /* 非 JSON 报错沿用兜底文案 */
        }
        finish(function () {
          callbacks.onError(message)
        })
      }
    },
    fail() {
      finish(function () {
        callbacks.onError('网络异常，请检查网络连接')
      })
    },
    complete() {
      // 冲掉残余字节后收尾
      if (buffer.length > 0) {
        textBuffer += utf8Decode(new Uint8Array(buffer))
        buffer = []
      }
      const rest = textBuffer.split('\n')
      for (let i = 0; i < rest.length; i++) {
        handleLine(rest[i])
      }
      finish(function () {
        callbacks.onDone()
      })
    },
  })

  task.onChunkReceived(function (res) {
    gotChunk = true
    const chunk = new Uint8Array(res.data)
    for (let i = 0; i < chunk.length; i++) {
      buffer.push(chunk[i])
    }

    // 仅在行尾（\n 为单字节）处切割解码，避免拆裂多字节字符
    let lastNewline = -1
    for (let i = buffer.length - 1; i >= 0; i--) {
      if (buffer[i] === 10) {
        lastNewline = i
        break
      }
    }
    if (lastNewline === -1) {
      return
    }

    const complete = new Uint8Array(buffer.slice(0, lastNewline + 1))
    buffer = buffer.slice(lastNewline + 1)
    textBuffer += utf8Decode(complete)

    const lines = textBuffer.split('\n')
    textBuffer = lines.pop() || ''
    for (let i = 0; i < lines.length; i++) {
      handleLine(lines[i])
    }
  })

  return {
    abort() {
      finished = true
      try {
        task.abort()
      } catch (e) {
        /* 已结束的请求 abort 静默忽略 */
      }
    },
  }
}
