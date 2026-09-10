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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * admin / api 端声明式路由匹配器
 *
 * 与前台 {@see PrettyRouteMatcher} 同构：对 RouteManifest 中按端归属的 declared 条目逐条
 * 编译为 PCRE（{@see PrettyUrlCompiler::compileToRegex}）。
 *
 * 匹配算法（resource 资源路径化后，URL 模式含 `{id}` 占位符，需消歧）：
 *   1. 收集**全部** URL 命中条目（不在首条即返回）；无命中 → 404（返回 null）。
 *   2. specificity 排序：占位符少者优先、字面段多者优先、声明顺序兜底
 *      （保证 `article/featured` 胜过 `article/{id}`）。
 *   3. 若传入 $httpMethod：按排序遍历，首个 method 命中即 HIT；全不命中 → 405
 *      （返回 {status:'method_not_allowed', allow:[...]}）。
 *   4. 未传 $httpMethod（permissive 调用，如 route-list 诊断）：返回排序后首条。
 *
 * 无命中且 routeRaw 仍为空时再 fallback 到 `index`（伪静态下访问 /admin/ 或 /api/ 时
 * .htaccess 写入 ?route= 空串，与显式 ?route=index 等价）。
 */
class BackendDeclaredMatcher
{
    /**
     * 匹配 routeRaw 到 declared 条目。
     *
     * @param string $routeRaw 已 trim 的 ?route= 原始路径
     * @param string $end 端命名空间字面量：'Admin' | 'Api'
     * @param string|null $httpMethod 当前 HTTP 方法（含 spoofing）；null = permissive 不做方法过滤
     * @return array|null 命中返回 {status:'hit', fqcn, action, module, sub, params, entry}；
     *                    方法不允许返回 {status:'method_not_allowed', allow:[...]}；URL 未命中返回 null
     */
    public static function match($routeRaw, $end, $httpMethod = null)
    {
        $routeRaw = trim((string) $routeRaw, '/');

        $hit = self::matchRouteRaw($routeRaw, $end, $httpMethod);
        if ($hit !== null) {
            return $hit;
        }
        if ($routeRaw === '') {
            return self::matchRouteRaw('index', $end, $httpMethod);
        }

        return null;
    }

    /**
     * 对单一路径片段执行 declared 匹配（specificity 排序 + HTTP 方法过滤）。
     *
     * @param string $routeRaw
     * @param string $end
     * @param string|null $httpMethod
     * @return array|null
     */
    private static function matchRouteRaw($routeRaw, $end, $httpMethod = null)
    {
        $candidates = array();
        $order = 0;
        foreach (RouteManifest::getEntriesByType('declared') as $entry) {
            if ($entry->endNamespace() !== $end) {
                continue;
            }
            if ($entry->controller === null || $entry->controller === '') {
                continue;
            }
            $regex = PrettyUrlCompiler::compileToRegex(
                $entry->pattern,
                is_array($entry->params) ? $entry->params : array()
            );
            if (!preg_match($regex, $routeRaw, $matches)) {
                $order++;
                continue;
            }
            $candidates[] = array(
                'entry' => $entry,
                'params' => self::namedCaptures($matches),
                'placeholders' => self::placeholderCount($entry->pattern),
                'literals' => self::literalSegmentCount($entry->pattern),
                'order' => $order,
            );
            $order++;
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, array(__CLASS__, 'compareSpecificity'));

        if ($httpMethod === null) {
            return self::hitArray($candidates[0]);
        }

        $allow = array();
        foreach ($candidates as $candidate) {
            /** @var RouteEntry $entry */
            $entry = $candidate['entry'];
            if ($entry->acceptsMethod($httpMethod)) {
                return self::hitArray($candidate);
            }
            foreach ($entry->methods as $m) {
                if (!in_array($m, $allow, true)) {
                    $allow[] = $m;
                }
            }
        }

        return array('status' => 'method_not_allowed', 'allow' => $allow);
    }

    /**
     * 把候选条目组装为 HIT 返回结构。
     *
     * @param array $candidate
     * @return array
     */
    private static function hitArray(array $candidate)
    {
        /** @var RouteEntry $entry */
        $entry = $candidate['entry'];
        return array(
            'status' => 'hit',
            'fqcn' => $entry->controller,
            'action' => (string) $entry->action,
            'module' => (string) $entry->module,
            'sub' => (string) $entry->sub,
            'params' => $candidate['params'],
            'entry' => $entry,
        );
    }

    /**
     * specificity 比较器：占位符少者优先 → 字面段多者优先 → 声明顺序兜底。
     *
     * @param array $a
     * @param array $b
     * @return int
     */
    private static function compareSpecificity($a, $b)
    {
        if ($a['placeholders'] !== $b['placeholders']) {
            return ($a['placeholders'] < $b['placeholders']) ? -1 : 1;
        }
        if ($a['literals'] !== $b['literals']) {
            return ($a['literals'] > $b['literals']) ? -1 : 1;
        }
        if ($a['order'] === $b['order']) {
            return 0;
        }
        return ($a['order'] < $b['order']) ? -1 : 1;
    }

    /**
     * pattern 内占位符 `{...}` 数量。
     *
     * @param string $pattern
     * @return int
     */
    private static function placeholderCount($pattern)
    {
        return preg_match_all('/\{[^}]+\}/', (string) $pattern, $m);
    }

    /**
     * pattern 内不含占位符的字面段数量（'/'-分段）。
     *
     * @param string $pattern
     * @return int
     */
    private static function literalSegmentCount($pattern)
    {
        $pattern = trim((string) $pattern, '/');
        if ($pattern === '') {
            return 0;
        }
        $count = 0;
        foreach (explode('/', $pattern) as $seg) {
            if ($seg !== '' && strpos($seg, '{') === false) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 抽取 preg_match 结果中的具名捕获组（去掉数字下标）。
     *
     * @param array $matches preg_match 输出
     * @return array
     */
    private static function namedCaptures(array $matches)
    {
        $result = array();
        foreach ($matches as $k => $v) {
            if (is_string($k) && $k !== '') {
                $result[$k] = $v;
            }
        }
        return $result;
    }
}
