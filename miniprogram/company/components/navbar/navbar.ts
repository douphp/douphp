// components/navbar/navbar.ts
import { isDebugEnv, isServerDebug } from '../../utils/env.js'
import { douPageTo } from '../../utils/ui.js'

const app = getApp<IAppOption>()

Component({
  properties: {
    title: {
      type: null, // 允许任意类型
      observer(newVal: unknown) {
        if (newVal === null || newVal === undefined) {
          this.setData({ innerTitle: '' })
        } else {
          this.setData({ innerTitle: String(newVal) })
        }
      },
    },
    backgroundColor: {
      type: null,
      observer(newVal: unknown) {
        if (newVal === null || newVal === undefined) {
          this.setData({ innerTitle: '' })
        } else {
          this.setData({ innerTitle: String(newVal) })
        }
      },
    },
    titleColor: {
      type: null,
      observer(newVal: unknown) {
        if (newVal === null || newVal === undefined) {
          this.setData({ innerTitle: '' })
        } else {
          this.setData({ innerTitle: String(newVal) })
        }
      },
    },
    url: { type: String, value: '' },
    showMenu: { type: Boolean, value: true },
  },

  data: {
    statusBarHeight: app.globalData.statusBarHeight + 'px',
    navigationBarHeight: app.globalData.navigationBarHeight + 'px',
    navigationBarHeightHalf: app.globalData.navigationBarHeight / 2 + 'px',
    menuButtonHeight: app.globalData.menuButtonHeight + 'px',
    navigationBarAndStatusBarHeight: app.globalData.navigationBarAndStatusBarHeight + 'px',
    // 是否渲染调试入口圆点（非正式版 + 服务端调试模式开启）
    debugDot: false,
  },

  lifetimes: {
    attached() {
      // 双门控：客户端 envVersion!=release + 服务端 debug_enable（site.ts 由 admin sync 写入）
      if (isDebugEnv() && isServerDebug()) {
        this.setData({ debugDot: true })
      }
    },
  },

  methods: {
    goBack(e: WechatMiniprogram.TouchEvent) {
      const url = (e.currentTarget.dataset.url as string) || ''
      if (url) {
        douPageTo(url)
      } else {
        wx.navigateBack()
      }
    },

    goHome() {
      wx.switchTab({ url: '/pages/index/index' })
    },

    // 点击环境角标进入调试信息页
    openDebug() {
      wx.navigateTo({ url: '/pages/debug/debug' })
    },
  },
})
