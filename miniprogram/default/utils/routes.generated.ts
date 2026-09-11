/**
 * api 命名路由表 —— 由后台 InstallService::writeMiniprogramRouteTableForSlug 整文件覆盖，请勿手改。
 *
 * key：路由名（已去 api. 前缀）；value：?route= 路径模板，{x} 为占位符，由 utils/route.ts 的 route() 填充。
 */

export const routes: Record<string, string> = {
  'article': 'article',
  'article.show': 'article/{id}',
  'bootstrap': 'bootstrap',
  'captcha': 'captcha',
  'captcha.token': 'captcha/token',
  'captcha.verification': 'captcha/verification',
  'index': 'index',
  'lang': 'lang',
  'page': 'page',
  'product': 'product',
  'product.attribute_list': 'product/attribute_list',
  'product.show': 'product/{id}',
  'product.work': 'product/work',
  'product.work.add': 'product/work/add',
  'product.work.destroy': 'product/work/{id}',
  'product.work.edit': 'product/work/edit',
  'product.work.store': 'product/work',
  'product.work.update': 'product/work/{id}',
  'product.work.upload': 'product/work/upload',
  'search': 'search',
}
