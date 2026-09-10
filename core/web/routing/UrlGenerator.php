<?php

/**
 * DouPHP®
 * ------------------------------------------------------------------------------------
 * Copyright (c) 2013-2026 漳州豆壳网络科技有限公司 (DouCo® Co.,Ltd.)
 *
 * 本软件基于 MIT 协议开源发布，完整协议文本见项目根目录 LICENSE 文件。
 * 网站地址：http://www.douphp.com
 * ------------------------------------------------------------------------------------
 * Author: DouCo Co.,Ltd.
 * Release Date: 2026-09-08
 */

namespace Dou\Core\Web\Routing;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 出站 URL 生成器：对 {@see UrlBuilder} 的薄包装，统一对外调用面。
 *
 * 内部组合 {@see UrlBuilder}，对外暴露短名方法，统一调用面：
 *
 * URL 构建：
 *   url($route, $params, $options)             ← UrlBuilder::buildFullUrl（点分路由 + 具名 params）
 *   urlMini($module, $value, $tabbar)          ← UrlBuilder::rewriteUrlMiniprogram
 *   getSlugPath($module, $id, $mode)           ← UrlBuilder::getSlugPath
 *   warmupUrlCache($module, $rows)             ← UrlBuilder::warmupUrlCache
 *
 * 调用约定：
 *   - 业务代码推荐用 helper `route('module.action', $params, $options)` 短写法直接拿 URL 字符串。
 *   - 需要 instance 方法（urlMini / warmupUrlCache / getSlugPath）时引入
 *     {@see \Dou\Core\Facade\Url} 静态门面：`use Dou\Core\Facade\Url;` 后 `Url::xxx(...)`。
 *
 * 调用契约（底线，不可放宽）：
 *   - $route 必须为点分路由键，$params 必须使用具名键（id/category_id/class 等）。
 *   - url() / UrlBuilder::buildFullUrl() 不为「不规范输入」做任何容错或猜测。
 *   - 异构数据源（如 nav 表 module/guide）由外部专用格式化器（参见 navStorageToUrlArgs）
 *     转成规范输入后再调用 url()，禁止把分支挪进入口层。
 *
 * 内部 UrlBuilder 通过 helper / 门面（`DB::`、`request()`、`locale()`、`Config`）
 * 就近解析所需依赖，无需外部传入运行时上下文。
 *
 * 业务实体合法性（category / column / single / page）由独立类
 * {@see RouteIdValidator} 承担；调用方走 {@see \Dou\Core\Facade\RouteId} 静态门面短名转发。
 */
class UrlGenerator
{
    /** @var UrlBuilder */
    private $urlBuilder;

    public function __construct()
    {
        $this->urlBuilder = new UrlBuilder();
    }

    // =========================================================================
    //  URL 构建
    // =========================================================================

    /**
     * 生成完整站点 URL
     *
     * $route 为点分路由键，如 user.login、user.contact.add、article.show、article.category、team.class。
     * $params 填 id、category_id、class、slug（仅 page.show 表示单页 slug 别名）等具名路径变量；
     * $options 含 page、query（附加查询串）、lang（预留）。
     *
     * 调用方必须传入符合契约的规范输入，本入口不接受不规范参数；
     * 异构数据源请先经外部格式化器（如 {@see UrlGenerator::navStorageToUrlArgs}）转换。
     *
     * @param string $route
     * @param array $params
     * @param array $options
     * @return string
     */
    public function url($route, array $params = array(), array $options = array())
    {
        $page = isset($options['page']) ? $options['page'] : '';

        // 浏览器端先经 RouteManifest::getEntryByName 命中声明式具名路由，
        // 直接按 entry pattern 出 URL（如 route('book.user') → /user/book）。
        // 小程序端跳过名查表，由 UrlBuilder::buildFullUrl 经 IS_MINIPROGRAM 分支走 urlMini。
        if (!(defined('IS_MINIPROGRAM') && IS_MINIPROGRAM)) {
            $entry = RouteManifest::getEntryByName((string) $route);
            if ($entry !== null && $entry->route_type === 'declared') {
                // admin / api 端：URL 形态固定为 `index.php?route=<填充 pattern>`（相对各端入口脚本，
                // 不经 ROOT_URL / 伪静态链），pattern 未消费的具名参数落 query；与前台 pseudo-static
                // 生成路径分离，保持 UrlBuilder 的前台契约不变。
                $end = $entry->endNamespace();
                if ($end === 'Admin' || $end === 'Api') {
                    return $this->buildBackendUrl($entry, $params, $options);
                }

                $values = $this->valuesForDeclaredEntry($entry, $params);
                $declaredUrl = $this->urlBuilder->buildFullUrlFromPattern($entry->pattern, $values, $page);
                if ($declaredUrl !== '') {
                    $placeholderNames = $this->entryPlaceholderNames($entry);
                    $extraQuery = array();
                    foreach ($params as $key => $value) {
                        if ($value !== null && !in_array($key, $placeholderNames, true)) {
                            $extraQuery[$key] = $value;
                        }
                    }
                    if (!empty($options['query']) && is_array($options['query'])) {
                        foreach ($options['query'] as $key => $value) {
                            if ($value !== null) {
                                $extraQuery[$key] = $value;
                            }
                        }
                    }
                    if (!empty($extraQuery)) {
                        $declaredUrl = $this->appendQuery($declaredUrl, $extraQuery);
                    }
                    return $declaredUrl;
                }
            }
        }

        $intent = $this->parseRouteToIntent((string) $route, $params);
        if ($intent === null) {
            return '';
        }
        $url = $this->urlBuilder->buildFullUrl($intent, $page);

        $consumedKeys = $this->intentConsumedKeys($intent);
        $extraQuery = array();
        foreach ($params as $key => $value) {
            if ($value !== null && !in_array($key, $consumedKeys, true)) {
                $extraQuery[$key] = $value;
            }
        }
        if (!empty($options['query']) && is_array($options['query'])) {
            foreach ($options['query'] as $key => $value) {
                if ($value !== null) {
                    $extraQuery[$key] = $value;
                }
            }
        }
        if (!empty($extraQuery)) {
            $url = $this->appendQuery($url, $extraQuery);
        }
        return $url;
    }

    /**
     * 生成 admin / api 端声明式条目的 URL（绝对地址）。
     *
     * 与前台不同：admin / api 各以本端入口脚本目录为基址（admin → ADMIN_URL、api → API_URL，
     * 均为含 host 的绝对地址），开启伪静态时输出 `BASE<填充 pattern>`，关闭时回退
     * `BASE index.php?route=<填充 pattern>`。伪静态开关分端取：admin 看 `system.admin_rewrite`，
     * api 复用 `site.rewrite`（与 front 同一开关）。输出绝对地址确保后台深路径（如
     * admin/product/35/edit）下链接 / 资源不受相对基准目录影响。
     *
     * pattern 内占位符 `{id}` 等由 $params 同名值填入；$params 中未被占位符消费的键
     * （含 id-in-query 形态下的 id / category_id）按声明顺序追加为 `&k=v`。
     *
     * @param RouteEntry $entry 命中的 admin / api declared 条目
     * @param array $params 具名参数
     * @param array $options page / query
     * @return string
     */
    private function buildBackendUrl(RouteEntry $entry, array $params, array $options)
    {
        $placeholderNames = array();
        if (preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]*)?\}/', (string) $entry->pattern, $matches)) {
            $placeholderNames = $matches[1];
        }

        $values = array();
        foreach ($placeholderNames as $name) {
            if (isset($params[$name])) {
                $values[$name] = (string) $params[$name];
            }
        }

        if ($entry->endNamespace() === 'Api') {
            $rewrite = (bool) Config::get('site.rewrite', false);
            $base = defined('API_URL') ? API_URL : '';
        } else {
            $rewrite = (bool) Config::get('system.admin_rewrite', false);
            $base = defined('ADMIN_URL') ? ADMIN_URL : '';
        }

        $path = PrettyUrlCompiler::fill((string) $entry->pattern, $values);
        $url = $rewrite ? ($base . $path) : ($base . 'index.php?route=' . $path);

        $query = array();
        foreach ($params as $key => $value) {
            if ($value === null || in_array($key, $placeholderNames, true)) {
                continue;
            }
            $query[$key] = $value;
        }
        if (!empty($options['query']) && is_array($options['query'])) {
            foreach ($options['query'] as $key => $value) {
                if ($value !== null) {
                    $query[$key] = $value;
                }
            }
        }
        if (isset($options['page']) && (string) $options['page'] !== '' && (string) $options['page'] !== '0') {
            $query['page'] = $options['page'];
        }

        foreach ($query as $key => $value) {
            $sep = strpos($url, '?') === false ? '?' : '&';
            $url .= $sep . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return $url;
    }

    /**
     * 取声明式条目 pattern 的占位符名集。
     *
     * @param RouteEntry $entry
     * @return string[]
     */
    private function entryPlaceholderNames(RouteEntry $entry)
    {
        $names = array();
        if (preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]*)?\}/', (string) $entry->pattern, $matches)) {
            $names = $matches[1];
        }
        return $names;
    }

    /**
     * 为声明式条目计算 pattern 占位符具名值。
     *
     * 无占位符的声明（如 `user/book`）直接返回空数组；带占位符的声明
     * （如 `user/{module}/{action}`）按 entry.params 名集 + $params 取值。
     *
     * @param RouteEntry $entry
     * @param array $params 调用方传入的具名 params
     * @return array<string, string>
     */
    private function valuesForDeclaredEntry(RouteEntry $entry, array $params)
    {
        if (empty($entry->params) && !$this->patternHasPlaceholder($entry->pattern)) {
            return array();
        }
        $values = array();
        foreach (array_keys((array) $entry->params) as $name) {
            if (isset($params[$name])) {
                $values[$name] = (string) $params[$name];
            }
        }
        // 兜底：pattern 内额外占位符也按 $params 同名值取
        if (preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]*)?\}/', (string) $entry->pattern, $matches)) {
            foreach ($matches[1] as $name) {
                if (!isset($values[$name]) && isset($params[$name])) {
                    $values[$name] = (string) $params[$name];
                }
            }
        }
        return $values;
    }

    /**
     * pattern 是否含占位符（与 PrettyUrlCompiler 接受的 mini 语法兼容）。
     *
     * @param string $pattern
     * @return bool
     */
    private function patternHasPlaceholder($pattern)
    {
        return strpos((string) $pattern, '{') !== false;
    }

    /**
     * 将点分路由 + 具名 $params 解析为 {@see UrlBuilder::buildFullUrl} 所需的结构化意图。
     *
     * 输出 $intent 字段：
     *   kind     list / detail / category / class / action2 / action3
     *   module   基础模块名（不带 _category 后缀）
     *   id       仅 detail：取自 $params['id']（page 可与 slug 二选一）
     *   slug     仅 page 模块的 detail：取自 $params['slug']，与 id 二选一优先用 slug 生成 URL
     *   category_id   仅 category：取自 $params['category_id']
     *   class    仅 class：取自 $params['class']
     *   seg2     仅 action2 / action3：URL 第 2 段
     *   seg3     仅 action3：URL 第 3 段（4 段及以上余段以 . 重新拼回）
     *
     * @param string $route
     * @param array $params
     * @return array|null 路由串为空时返回 null
     */
    private function parseRouteToIntent($route, array $params)
    {
        $route = trim($route);
        if ($route === '') {
            return null;
        }

        $parts = explode('.', $route);
        $n = count($parts);
        $module = $parts[0];

        if ($n === 1) {
            return array('kind' => 'list', 'module' => $module);
        }

        $last = $parts[$n - 1];

        if ($n === 2 && $last === 'show' && $this->isDetailSyntheticBase($module)) {
            if ($module === 'page' && isset($params['slug']) && (string) $params['slug'] !== '') {
                return array(
                    'kind' => 'detail',
                    'module' => 'page',
                    'slug' => $params['slug'],
                );
            }
            return array(
                'kind' => 'detail',
                'module' => $module,
                'id' => isset($params['id']) ? $params['id'] : '',
            );
        }

        if ($n === 2 && $last === 'category') {
            return array(
                'kind' => 'category',
                'module' => $module,
                'category_id' => isset($params['category_id']) ? $params['category_id'] : '',
            );
        }

        if ($n === 2 && $last === 'class') {
            return array(
                'kind' => 'class',
                'module' => $module,
                'class' => isset($params['class']) ? $params['class'] : '',
            );
        }

        if ($n === 2) {
            return array(
                'kind' => 'action2',
                'module' => $module,
                'seg2' => $parts[1],
            );
        }

        // 三段及以上：module / sub / action（多于三段时余段以 . 拼回 action 段）
        $seg3 = ($n === 3) ? $parts[2] : implode('.', array_slice($parts, 2));
        return array(
            'kind' => 'action3',
            'module' => $module,
            'seg2' => $parts[1],
            'seg3' => $seg3,
        );
    }

    /**
     * intent 已经消费的参数键（parseRouteToIntent 从 $params 取值的键集）。
     *
     * @param array $intent
     * @return string[]
     */
    private function intentConsumedKeys(array $intent)
    {
        $keys = array();
        $kind = isset($intent['kind']) ? $intent['kind'] : '';
        if ($kind === 'detail') {
            if (isset($intent['slug']) && (string) $intent['slug'] !== '') {
                $keys[] = 'slug';
            } elseif (isset($intent['id']) && $intent['id'] !== '') {
                $keys[] = 'id';
            }
        }
        if ($kind === 'category' && isset($intent['category_id']) && $intent['category_id'] !== '') {
            $keys[] = 'category_id';
        }
        if ($kind === 'class' && isset($intent['class']) && $intent['class'] !== '') {
            $keys[] = 'class';
        }
        return $keys;
    }

    /**
     * .show 合成键是否应用于该模块（page / 栏目 / 单表模块均按内容详情解析）。
     * 栏目 / 单表判定经 {@see ModuleRegistry}（与三端入站解析共用同一来源）。
     *
     * @param string $base
     * @return bool
     */
    private function isDetailSyntheticBase($base)
    {
        if ($base === 'page') {
            return true;
        }
        $registry = new ModuleRegistry();
        return $registry->isColumn($base) || $registry->isSingle($base);
    }

    /**
     * 使用 Util::normalizeQueryString 追加查询串（与业务层手工拼接 &k=v、首个 & 转 ? 行为一致）
     *
     * @param string $url
     * @param array $query
     * @return string
     */
    private function appendQuery($url, array $query)
    {
        $qs = '';
        foreach ($query as $k => $v) {
            if ($v === null) {
                continue;
            }
            $qs .= '&' . rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        if ($qs === '') {
            return $url;
        }
        return Util::normalizeQueryString($url . $qs);
    }

    /**
     * 将 nav 表存储格式（module + guide）转换为 {@see UrlGenerator::url} 的规范输入。
     *
     * 仅作为 UrlGenerator 入口外的格式化器存在，不在 url() 内部识别 nav 形态。
     * 返回 array($route, $params, $options)，可直接展开传入 url()：
     *
     *   list($r, $p, $o) = UrlGenerator::navStorageToUrlArgs($module, $guide);
     *   $url = route($r, $p, $o);
     *
     * 转换规则：
     *   - 模块以 _category 结尾：{base}.category + ['category_id' => guide]（含 0 表示分类根）
     *   - 数字型 guide：{module}.show + ['id' => guide]（栏目 / 单表；page 亦可用数字 id）
     *   - page + 非数字 guide：page.show + ['slug' => guide]（如 agreement）
     *   - 非数字 guide：{module}.{guide}（动作段）
     *   - guide 为空：{module}（列表）
     *
     * @param string $module
     * @param string|int $guide
     * @return array array(string route, array params, array options)
     */
    public static function navStorageToUrlArgs($module, $guide)
    {
        $module = (string) $module;
        $guide = (string) $guide;

        if ($module === '') {
            return array('', array(), array());
        }

        $categorySuffix = '_category';
        $suffixLen = strlen($categorySuffix);
        if ($suffixLen < strlen($module) && substr($module, -$suffixLen) === $categorySuffix) {
            $base = substr($module, 0, -$suffixLen);
            return array($base . '.category', array('category_id' => $guide), array());
        }

        if ($guide === '') {
            return array($module, array(), array());
        }

        if ($module === 'page' && !is_numeric($guide)) {
            return array('page.show', array('slug' => $guide), array());
        }

        if (is_numeric($guide)) {
            return array($module . '.show', array('id' => $guide), array());
        }

        return array($module . '.' . $guide, array(), array());
    }

    /**
     * 生成小程序 pages 路径
     *
     * @param string $module
     * @param string|int $value
     * @param bool $tabbar
     * @return string
     */
    public function urlMini($module, $value = '', $tabbar = false)
    {
        return $this->urlBuilder->rewriteUrlMiniprogram($module, $value, $tabbar);
    }

    /**
     * 获取模块 / 分类的别名路径片段
     *
     * @param string $module
     * @param string|int $id
     * @param string $mode
     * @return string
     */
    public function getSlugPath($module, $id, $mode = 'full')
    {
        return $this->urlBuilder->getSlugPath($module, $id, $mode);
    }

    /**
     * 批量预热 URL 字段缓存（列表页调用）
     *
     * @param string $module
     * @param array $rows
     * @return void
     */
    public function warmupUrlCache($module, $rows)
    {
        $this->urlBuilder->warmupUrlCache($module, $rows);
    }
}
