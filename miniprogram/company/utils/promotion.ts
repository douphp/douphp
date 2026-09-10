/**
 * 推广分销候选 user_sn 持久化。
 *
 * 与后端 front\Controller\User\UserController 的 Session::get('promotion_user_sn') 等价：
 * 扫码 / 链接进入的访客先把 user_sn 缓存本地，注册（register / login_phone / login_weixin
 * 自动建号）成功时由后端写入 user.direct_user_id / indirect_user_id；任意登录成功后清空。
 */

const PROMOTION_USER_SN_KEY = 'promotion_user_sn'

export function setPromotionUserSn(sn: string | number | undefined | null): void {
  if (sn === undefined || sn === null || sn === '') return
  const raw = String(sn).trim()
  if (raw === '' || !/^\d+$/.test(raw)) return
  try {
    wx.setStorageSync(PROMOTION_USER_SN_KEY, raw)
  } catch (e) {
    /* storage 不可用时静默忽略 */
  }
}

export function getPromotionUserSn(): string {
  try {
    const v = wx.getStorageSync(PROMOTION_USER_SN_KEY)
    return v ? String(v) : ''
  } catch (e) {
    return ''
  }
}

export function clearPromotionUserSn(): void {
  try {
    wx.removeStorageSync(PROMOTION_USER_SN_KEY)
  } catch (e) {
    /* storage 不可用时静默忽略 */
  }
}
