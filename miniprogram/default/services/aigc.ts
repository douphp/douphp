/**
 * aigc 服务层：应用、生成任务、作品、套餐与私有产物下载。
 *
 * - 生成提交统一用 fields_json（wx.request 表单序列化不支持嵌套对象）
 * - 产物文件需带 Authorization 头下载，不能直接作为 <image src>
 */

import { http } from './http.js'
import { route } from '../utils/route.js'

/** 应用中心 / 应用列表 */
export function getApps(): Promise<any> {
  return http.get(route('aigc'))
}

/** 应用详情：字段、模型、配额 */
export function getApp(slug: string): Promise<any> {
  return http.get(route('aigc.app'), { slug: slug })
}

/** 提交生成任务 */
export function submit(slug: string, fields: Record<string, any>): Promise<any> {
  return http.post(
    route('aigc.submit'),
    {
      slug: slug,
      fields_json: JSON.stringify(fields || {}),
    },
    { timeout: 120000 }
  )
}

/** 轮询生成任务 */
export function poll(taskId: number | string): Promise<any> {
  return http.get(route('aigc.poll'), { id: taskId }, { cache: false, dedupe: false, timeout: 30000 })
}

/** 我的作品 */
export function getWorks(type = '', page: number | string = 1): Promise<any> {
  return http.get(route('aigc.works'), { type: type, page: page }, { cache: false, dedupe: false })
}

/** 删除作品 */
export function deleteWork(id: number | string): Promise<any> {
  return http.post(route('aigc.task_delete'), { id: id })
}

/** 套餐列表 */
export function getPackages(): Promise<any> {
  return http.get(route('aigc.package'))
}

/** 我的配额与订阅 */
export function getUser(): Promise<any> {
  return http.get(route('aigc.user'), {}, { cache: false, dedupe: false })
}

/**
 * 下载私有产物到本地临时路径。
 *
 * @param taskId 任务 ID
 * @param index 文件序号
 * @param mode inline|download
 * @returns 临时文件路径
 */
export function downloadWorkFile(taskId: number | string, index: number | string, mode = 'inline'): Promise<string> {
  return new Promise(function (resolve, reject) {
    wx.downloadFile({
      url: route('aigc.file', { id: taskId, index: index, mode: mode }),
      header: {
        Authorization: 'Bearer ' + (wx.getStorageSync('api_token') || ''),
      },
      success(res) {
        if (res.statusCode === 200 && res.tempFilePath) {
          resolve(res.tempFilePath)
        } else {
          reject(new Error('文件下载失败'))
        }
      },
      fail() {
        reject(new Error('网络异常，文件下载失败'))
      },
    })
  })
}

/**
 * 下载并打开文档（pptx / xlsx / pdf）。
 */
export function openWorkFile(taskId: number | string, index: number | string, format: string): Promise<void> {
  return downloadWorkFile(taskId, index, 'download').then(function (filePath) {
    return new Promise<void>(function (resolve, reject) {
      wx.openDocument({
        filePath: filePath,
        fileType: format as any,
        showMenu: true,
        success() {
          resolve()
        },
        fail() {
          reject(new Error('文件打开失败'))
        },
      })
    })
  })
}
