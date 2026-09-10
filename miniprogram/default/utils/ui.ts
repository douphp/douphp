/**
 * 通用 UI / 导航辅助：toast 提示、跳转、当前页路径、分享菜单。
 */

import { isTabBarPath } from './tabbar.js'

/** 获取当前页带参数的 url（形如 /pages/x/x?a=1&b=2） */
export function douGetCurrentPages(): string {
  const pages = getCurrentPages()
  const currentPage = pages[pages.length - 1]
  const url = currentPage.route
  const options = (currentPage.options || {}) as Record<string, string>

  let urlWithArgs = url + '?'
  for (const key in options) {
    urlWithArgs += key + '=' + options[key] + '&'
  }
  return '/' + urlWithArgs.substring(0, urlWithArgs.length - 1)
}

/**
 * 返回 / 跳转到指定路径页面。
 *
 * 决策顺序：
 *   1. 目标已在当前页面栈 -> wx.navigateBack 回退（保留栈结构）
 *   2. 目标是 tabBar 页 -> wx.switchTab（运行时按 __wxConfig.tabBar.list 判定，
 *      不写死路径白名单，自动适配模块化裁剪后的 tabBar）
 *   3. 其余 -> wx.redirectTo
 */
export function douPageTo(url: string): void {
  const urlFormat = url.replace(/^\/+|\/+$/g, '')
  const pages = getCurrentPages()
  const targetIndex = pages.findIndex((page) => page.route === urlFormat)

  if (targetIndex === -1) {
    if (isTabBarPath(url)) {
      wx.switchTab({ url })
    } else {
      wx.redirectTo({ url })
    }
  } else {
    const delta = pages.length - 1 - targetIndex
    if (delta > 0) {
      wx.navigateBack({ delta })
    }
  }
}

/** toast 提示，可选 time 后跳转（'back' 返回，tabBar 路径 switchTab，其余 douPageTo） */
export function douMsg(message: string, url = '', time = 2000): void {
  wx.showToast({ title: message, icon: 'none', duration: time })

  if (url) {
    setTimeout(function () {
      if (url === 'back') {
        wx.navigateBack()
      } else if (isTabBarPath(url)) {
        wx.switchTab({ url })
      } else {
        douPageTo(url)
      }
    }, time)
  }
}

/** 开启分享菜单（转发 + 朋友圈） */
export function showShareMenu(): void {
  wx.showShareMenu({
    withShareTicket: true,
    menus: ['shareAppMessage', 'shareTimeline'],
  })
}
