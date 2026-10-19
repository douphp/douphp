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
 * 路由 pattern specificity 计算与比较（前后端 matcher 共用）。
 *
 * 排序语义：占位符少者优先 → 字面段多者优先 → 声明顺序兜底。
 * 仅抽纯函数；两套 matcher 的匹配流程、空捕获语义与规则装载契约不同，不合并。
 */
class RouteSpecificity
{
    /**
     * specificity 比较器。
     *
     * @param array $a 含 placeholders / literals / order
     * @param array $b 含 placeholders / literals / order
     * @return int
     */
    public static function compare(array $a, array $b)
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
    public static function placeholderCount($pattern)
    {
        return preg_match_all('/\{[^}]+\}/', (string) $pattern, $m);
    }

    /**
     * pattern 内不含占位符的字面段数量（'/'-分段）。
     *
     * @param string $pattern
     * @return int
     */
    public static function literalSegmentCount($pattern)
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
}
