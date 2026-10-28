/**
 * 表单参数扁平化 —— 把 JS 数组/对象转成 PHP 可解析的 key[0]/key[a][b] 形态。
 *
 * wx.request 对 x-www-form-urlencoded 的数组序列化不可依赖，API 端的
 * `$request->input('xxx')` 需要 PHP 原生数组结构，统一在请求前展开。
 *
 *   flattenParams('question_ids', [1, 2])
 *     -> { 'question_ids[0]': 1, 'question_ids[1]': 2 }
 *   flattenParams('items', [{ keyword: 'a', questions: ['q'] }])
 *     -> { 'items[0][keyword]': 'a', 'items[0][questions][0]': 'q' }
 */

export function flattenParams(prefix: string, value: any, out?: Record<string, any>): Record<string, any> {
  const result = out || {}

  if (Array.isArray(value)) {
    for (let i = 0; i < value.length; i++) {
      flattenParams(prefix + '[' + i + ']', value[i], result)
    }
    return result
  }

  if (value !== null && typeof value === 'object') {
    const keys = Object.keys(value)
    for (let i = 0; i < keys.length; i++) {
      const key = keys[i]
      flattenParams(prefix + '[' + key + ']', value[key], result)
    }
    return result
  }

  result[prefix] = value
  return result
}
