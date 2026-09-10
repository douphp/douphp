/**
 * 文件上传 / 删除 —— user/filebox、user/filedel。
 *
 * 需登录：先 authStore.ensureLogin()（未登录自动跳登录页），登录后再执行。
 * wx.uploadFile 返回字符串，需手动解析信封。
 */

import { http } from './http.js'
import { route } from '../utils/route.js'
import { authStore } from '../stores/auth.js'

export interface FileboxDataset {
  type: string
  module: string
  item_id?: string | number
  folder?: string
  draft_token?: string
}

type ImgList = any[]
type ImgListCallback = (imgList: ImgList) => void

/** 选图并上传到 user/filebox，回调返回 img_list */
export function filebox(params: { dataset: FileboxDataset }, callback: ImgListCallback): void {
  authStore.ensureLogin().then((ok) => {
    if (!ok) {
      return
    }
    wx.chooseImage({
      count: 1,
      sizeType: ['original', 'compressed'],
      sourceType: ['album', 'camera'],
      success(info) {
        const tempFilePaths = info.tempFilePaths
        wx.uploadFile({
          url: route('user.filebox'),
          filePath: tempFilePaths[0],
          formData: {
            type: params.dataset.type,
            module: params.dataset.module,
            item_id: params.dataset.item_id || '',
            folder: params.dataset.folder || 'no',
            draft_token: params.dataset.draft_token || '',
          },
          name: 'boxfield',
          header: {
            Authorization: 'Bearer ' + (wx.getStorageSync('api_token') || ''),
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          success(res) {
            let raw: any = {}
            try {
              raw = res && res.data ? JSON.parse(res.data) : {}
            } catch (e) {
              raw = {}
            }
            const data =
              raw && raw.code === 'OK' && raw.data && typeof raw.data === 'object' ? raw.data : {}
            callback(Array.isArray(data.img_list) ? data.img_list : [])
          },
        })
      },
    })
  })
}

/** 删除已上传文件（user/filedel），回调返回剩余 img_list */
export function fileDel(params: { dataset: { number: string | number } }, callback: ImgListCallback): void {
  authStore.ensureLogin().then((ok) => {
    if (!ok) {
      return
    }
    http
      .post<{ img_list?: ImgList }>(route('user.filedel'), {
        number: params.dataset.number,
      })
      .then((data) => {
        callback(data && Array.isArray(data.img_list) ? data.img_list : [])
      })
      .catch(() => {
        callback([])
      })
  })
}
