/**
 * 验证码按钮倒计时控制（对齐 PC theme/default/js/captcha.js 的 time() 逻辑）。
 *
 * 操作 Page 的 data.<field>，模板按 {{countdown > 0}} 渲染按钮文案。
 *
 *   onLoad() { this.countdown = captchaCountdown(this) }   // field 默认 'countdown'
 *   // 发送成功后：this.countdown.start(60)
 *   onUnload() { this.countdown.stop() }
 */

export interface Countdown {
  start: (seconds?: number) => void
  stop: () => void
}

interface CountdownOptions {
  field?: string
  seconds?: number
}

type CountdownPage = WechatMiniprogram.Page.TrivialInstance

export function captchaCountdown(page: CountdownPage, options?: CountdownOptions): Countdown {
  const opts = options || {}
  const field = opts.field || 'countdown'
  const defaultSeconds = opts.seconds || 60
  const timerKey = '__' + field + 'Timer'

  const setField = function (val: number) {
    const patch: Record<string, number> = {}
    patch[field] = val
    page.setData(patch)
  }

  const stop = function () {
    if (page[timerKey]) {
      clearInterval(page[timerKey])
      page[timerKey] = null
    }
  }

  const start = function (seconds?: number) {
    stop()
    let remaining = seconds || defaultSeconds
    setField(remaining)
    page[timerKey] = setInterval(function () {
      remaining--
      if (remaining <= 0) {
        stop()
        setField(0)
      } else {
        setField(remaining)
      }
    }, 1000)
  }

  return { start, stop }
}
