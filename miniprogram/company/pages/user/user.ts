// pages/user/user.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { authStore, cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'
import { pageTitle } from '../../utils/page_title.js'

Page({
  data: {} as Record<string, any>,

  onLoad() {
    const that = this

    that.setData({ title: pageTitle('user') })

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })
  },

  onUnload() {

    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },

  onShow() {
    const that = this

    http
      .get(route('user'), {
      })
      .then(function (data) {
        that.setData({
          dou: data.dou || null,
          welcome: data.welcome || '',
          link_user_center: data.link_user_center || {},
          if_connect_plugin: data.if_connect_plugin || false,
        })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })

    // tabBar 购物车角标由 cartStore.badge 统一驱动
    cartStore.refresh()
  },

  userLogout() {
    wx.clearStorageSync() // 清空缓存，退出登录
    authStore.logout()
    cartStore.reset()
    wx.reLaunch({ url: '/pages/index/index' })
  },

  // 下载图片
  downloadImage(e: WechatMiniprogram.TouchEvent) {
    const imageUrl = e.currentTarget.dataset.url

    wx.showLoading({ title: '下载中...', mask: true })

    wx.downloadFile({
      url: imageUrl,
      success: (res) => {
        if (res.statusCode === 200) {
          wx.saveImageToPhotosAlbum({
            filePath: res.tempFilePath,
            success: () => {
              wx.hideLoading()
              wx.showToast({ title: '保存成功', icon: 'success', duration: 2000 })
            },
            fail: (err) => {
              wx.hideLoading()
              console.error('保存失败:', err)
              wx.showToast({ title: '保存失败', icon: 'none', duration: 2000 })
            },
          })
        }
      },
      fail: (err) => {
        wx.hideLoading()
        console.error('下载失败:', err)
        wx.showToast({ title: '下载失败', icon: 'none', duration: 2000 })
      },
    })
  },

  // 复制文本
  copyText(e: WechatMiniprogram.TouchEvent) {
    const text = e.currentTarget.dataset.text

    wx.setClipboardData({
      data: text,
      success() {
        wx.showToast({ title: '复制成功', icon: 'success', duration: 2000 })
      },
      fail(err) {
        console.error('复制失败:', err)
        wx.showToast({ title: '复制失败', icon: 'none', duration: 2000 })
      },
    })
  },

  // 非 tabBar 页面，可带参数
  douNavigateTo(e: WechatMiniprogram.TouchEvent) {
    const url = e.currentTarget.dataset.url
    wx.navigateTo({ url })
  },
})
