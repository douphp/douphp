/**
 * tabBar 运行时形态读取层。
 *
 * 发布版 app.json 的 tabBar 由后台 MiniprogramNavigationBuilder 按已启用模块
 * 动态生成，不同站点的 tab 顺序 / 个数都可能不同：
 *   - 装齐 user + order：[index, product_category, order, user]
 *   - 不装 order：           [index, product_category, user]
 *   - 不装 user + order：    [index, product_category]
 *
 * 业务代码（购物车角标、douPageTo / douMsg 的 switchTab 判定等）必须按运行时
 * 真相决策，禁止再出现「写死 index: N」「写死路径白名单」这类硬编码。
 *
 * 数据源：基础库注入的 __wxConfig.tabBar.list（其 pagePath 会带 .html / .js /
 * .wxml 等渲染层后缀，统一经 normalizePagePath 收口）。
 */

/** 缓存：path（normalize 后）-> 在 tabBar.list 中的真实 index */
let cachedPathToIndex: Map<string, number> | null = null

/**
 * 规范化页面路径用于稳健比对。
 *
 * 同时处理三种差异：
 *   - 前导 '/'：'/pages/x/x' vs 'pages/x/x'
 *   - 查询串：'pages/x/x?id=1' -> 'pages/x/x'
 *   - 渲染层后缀：基础库 3.14.1 给 __wxConfig.tabBar.list[].pagePath 加 '.html'
 */
export function normalizePagePath(p: string): string {
  if (!p) {
    return ''
  }
  const qIndex = p.indexOf('?')
  const noQuery = qIndex >= 0 ? p.substring(0, qIndex) : p
  return noQuery.replace(/^\/+/, '').replace(/\.(html|js|wxml)$/, '')
}

/**
 * 扫一次 __wxConfig.tabBar.list 建立 path -> index 映射并缓存。
 *
 * tabBar 在 app 生命周期内不变（只有重启 / 重新安装才变），不做失效逻辑。
 * __wxConfig 缺失 / 结构异常时退化为空 Map，调用方按「未配置」处理。
 */
function getPathToIndex(): Map<string, number> {
  if (cachedPathToIndex !== null) {
    return cachedPathToIndex
  }
  const map = new Map<string, number>()
  try {
    const list = (typeof __wxConfig !== 'undefined' && __wxConfig && __wxConfig.tabBar && __wxConfig.tabBar.list) || []
    for (let i = 0; i < list.length; i++) {
      const raw = (list[i] && list[i].pagePath) || ''
      const normalized = normalizePagePath(raw)
      if (normalized) {
        map.set(normalized, i)
      }
    }
  } catch (e) {
    /* 异常环境下保持空 Map */
  }
  cachedPathToIndex = map
  return map
}

/**
 * 给定页面路径，返回其在 tabBar.list 的真实下标。
 *
 * @param pagePath 任意形态的页面路径（'/pages/x/x' / 'pages/x/x' / 带 query 均可）
 * @returns >=0 真实下标；-1 不在 tabBar（未装对应模块 / 不在 tab 配置中）
 */
export function resolveTabIndex(pagePath: string): number {
  const key = normalizePagePath(pagePath)
  if (!key) {
    return -1
  }
  const idx = getPathToIndex().get(key)
  return typeof idx === 'number' ? idx : -1
}

/**
 * 给定页面路径，判断是否属于 tabBar 页。
 *
 * 用于跳转策略选择：tabBar 页用 wx.switchTab，非 tabBar 页用 wx.redirectTo /
 * wx.navigateTo，写死路径白名单会与发布版 app.json 漂移。
 */
export function isTabBarPath(pagePath: string): boolean {
  return resolveTabIndex(pagePath) >= 0
}
