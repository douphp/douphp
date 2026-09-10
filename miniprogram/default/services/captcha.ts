/**
 * 验证码下发 —— 对齐 PC theme/default/js/captcha.js 的 sendCaptcha。
 *
 * 小程序无 session：服务端返回 { verification: {account, ontime, code} }，
 * 由客户端 storage 缓存，提交表单时随 ver_account/ver_ontime/ver_code 回传。
 */

import { ApiError, http } from './http.js'
import { route } from '../utils/route.js'

export interface Verification {
  account: string
  ontime: number
  code: string
}

export interface SendCaptchaOptions {
  type: 'sms' | 'email'
  account: string
  captcha_token: string
  storage_captcha_token: string
  /** 默认 'no_allow_phone_exist'；密码找回场景传 '' 放行已存在账号 */
  check?: string
}

export function sendCaptcha(options: SendCaptchaOptions): Promise<Verification> {
  return http
    .post<{ message?: string; verification?: Verification }>(route('captcha.verification'), {
      type: options.type,
      account: options.account,
      captcha_token: options.captcha_token,
      storage_captcha_token: options.storage_captcha_token,
      check: typeof options.check !== 'undefined' ? options.check : 'no_allow_phone_exist',
    })
    .then((data) => {
      if (!data || data.message !== 'success' || !data.verification) {
        return Promise.reject(
          new ApiError({
            code: 'BUSINESS_RULE_VIOLATION',
            message: (data && data.message) || '发送失败',
            statusCode: 0,
            data: data || {},
          })
        )
      }
      return data.verification
    })
}
