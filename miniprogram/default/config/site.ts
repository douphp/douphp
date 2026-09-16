/**
 * 站点级运行数据 —— 由后台 InstallService::syncMiniprogramRuntimeConfig 整文件覆盖。
 *
 * - root_url / mp_url：站点部署域名派生
 * - rewrite_enable：镜像 site.rewrite（与 front / API 伪静态同一开关）
 * - debug_enable：镜像 admin 站点调试开关
 * - douLoading：全局 loading UI 开关
 *
 * 业务侧请直接 `import { mp_url } from '../config/site.js'`，无须额外薄壳。
 */

export const root_url = 'https://your-domain.com/'
export const mp_url = 'https://your-domain.com/api/'
export const douLoading = true
export const debug_enable = true
export const rewrite_enable = true
