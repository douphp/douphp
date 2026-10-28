// behaviors/navScroll.ts

/**
 * 导航栏滚动显隐 Behavior
 *
 * 微信小程序的 onPageScroll 是页面级生命周期，组件无法直接监听。
 * 通过 Behavior 封装，页面只需引入一行即可获得滚动显隐能力：
 *
 *   import { navScrollBehavior } from '../../behaviors/navScroll.js'
 *   Page({
 *     behaviors: [navScrollBehavior],
 *     // 页面须显式声明 onPageScroll，框架才会注册滚动监听；
 *     // Behavior 根级 onPageScroll 会先于页面回调执行。
 *     onPageScroll() {},
 *     ...
 *   })
 *
 * 然后在 wxml 中传值给 navbar：
 *   <navbar scrollOpacity="{{navOpacity}}"></navbar>
 *
 * 可通过 data.navScrollDistance 调整触发阈值（默认 300px）：
 * 滚动未达阈值时完全透明，达到或超过后直接全显（无渐变过程）
 */
export const navScrollBehavior = Behavior({
  data: {
    navOpacity: 0, // 导航栏背景透明度，仅 0（透明）或 1（全显）
    navScrollDistance: 80, // 触发全显的滚动阈值（px）
  },

  // 页面生命周期须放在 Behavior 根级，才会与 Page 生命周期链式合并
  onPageScroll(e: { scrollTop: number }) {
    ;(this as any).updateNavOpacity(e)
  },

  methods: {
    /**
     * 按滚动阈值切换导航栏显隐（可供页面 onPageScroll 显式转发）
     *
     * @param e 页面滚动事件
     */
    updateNavOpacity(e: { scrollTop: number }) {
      const scrollTop = e && typeof e.scrollTop === 'number' ? e.scrollTop : 0
      const configured = Number((this as any).data.navScrollDistance)
      const threshold = configured > 0 ? configured : 80
      const opacity = scrollTop >= threshold ? 1 : 0
      if (opacity !== (this as any).data.navOpacity) {
        ;(this as any).setData({ navOpacity: opacity })
      }
    },
  },
})
