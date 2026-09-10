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
 * 路由 pattern 唯一编译器（双向）
 *
 * config/route.php 的 pattern 迷你语言（`{name}` / `{name:regex}` / `[/optional]`）的唯一解释器：
 * 入站匹配（PrettyRouteMatcher）与出站生成（UrlBuilder）共用本类的同一个括号配平扫描器，
 * 保证两侧对同一 pattern 的理解永远一致（双向可逆）：
 *
 *   compileToRegex()  pattern → 带具名捕获组的 PCRE（入站匹配）
 *   fill()            pattern + 具名值 → 具体路径（出站生成）
 *
 * 语法：
 *   {name}          占位符，默认子模式 [^/]+，可由 $params[name] 覆盖
 *   {name:regex}    内联正则优先于 $params[name]
 *   [ ... ]         可选段：fill 时内部任一占位符取到非空值才渲染；compile 时编译为 (?:...)?
 */
class PrettyUrlCompiler
{
    /** @var array<string, string> 编译后正则的进程级缓存 */
    private static $regexCache = array();

    /**
     * 清空进程内编译缓存（单测或配置热更后调用）。
     *
     * @return void
     */
    public static function clearCache()
    {
        self::$regexCache = array();
    }

    /**
     * pattern → 带具名捕获组的 PCRE（含起止锚点与 u 修饰符）。
     *
     * @param string $pattern
     * @param array $params 占位符默认子模式（pattern 内联正则优先）
     * @return string
     */
    public static function compileToRegex($pattern, array $params = array())
    {
        $cacheKey = $pattern . '|' . serialize($params);
        if (isset(self::$regexCache[$cacheKey])) {
            return self::$regexCache[$cacheKey];
        }

        $regex = '#^' . self::compile($pattern, $params) . '$#u';
        self::$regexCache[$cacheKey] = $regex;

        return $regex;
    }

    /**
     * pattern + 具名值 → 具体路径片段（不含 ROOT_URL / 语言前缀 / 分页）。
     *
     * @param string $pattern
     * @param array $values
     * @return string
     */
    public static function fill($pattern, array $values)
    {
        $result = '';
        $len = strlen($pattern);
        $i = 0;

        while ($i < $len) {
            $ch = $pattern[$i];

            if ($ch === '[') {
                $end = self::skipBalancedBrackets($pattern, $i, '[', ']');
                if ($end === $i) {
                    $result .= $ch;
                    $i++;
                    continue;
                }
                $inner = substr($pattern, $i + 1, $end - $i - 2);
                if (self::shouldRenderOptionalSegment($inner, $values)) {
                    $result .= self::fill($inner, $values);
                }
                $i = $end;
                continue;
            }

            if ($ch === '{') {
                $end = self::skipBalancedBrackets($pattern, $i, '{', '}');
                if ($end === $i) {
                    $result .= $ch;
                    $i++;
                    continue;
                }
                $placeholder = substr($pattern, $i + 1, $end - $i - 2);
                $colon = strpos($placeholder, ':');
                $name = ($colon !== false) ? substr($placeholder, 0, $colon) : $placeholder;
                $result .= isset($values[$name]) ? $values[$name] : '';
                $i = $end;
                continue;
            }

            $result .= $ch;
            $i++;
        }

        return preg_replace('#/{2,}#', '/', $result);
    }

    /**
     * 递归编译 pattern 为正则片段（不含锚点）。
     *
     * @param string $pattern
     * @param array $params
     * @return string
     */
    private static function compile($pattern, array $params)
    {
        $result = '';
        $len = strlen($pattern);
        $i = 0;

        while ($i < $len) {
            $ch = $pattern[$i];

            if ($ch === '[') {
                $end = self::skipBalancedBrackets($pattern, $i, '[', ']');
                if ($end === $i) {
                    $result .= preg_quote($ch, '#');
                    $i++;
                    continue;
                }
                $inner = substr($pattern, $i + 1, $end - $i - 2);
                $result .= '(?:' . self::compile($inner, $params) . ')?';
                $i = $end;
                continue;
            }

            if ($ch === '{') {
                $end = self::skipBalancedBrackets($pattern, $i, '{', '}');
                if ($end === $i) {
                    $result .= preg_quote($ch, '#');
                    $i++;
                    continue;
                }
                $placeholder = substr($pattern, $i + 1, $end - $i - 2);
                if (strpos($placeholder, ':') !== false) {
                    list($name, $inlineRe) = explode(':', $placeholder, 2);
                } else {
                    $name = $placeholder;
                    $inlineRe = null;
                }
                $sub = ($inlineRe !== null)
                    ? $inlineRe
                    : (isset($params[$name]) ? $params[$name] : '[^/]+');
                $result .= '(?P<' . $name . '>' . $sub . ')';
                $i = $end;
                continue;
            }

            $result .= preg_quote($ch, '#');
            $i++;
        }

        return $result;
    }

    /**
     * 可选段 [...] 是否应渲染：内部任一占位符在 $values 中有非空值即渲染。
     *
     * @param string $inner 可选段内部（去掉外层方括号）
     * @param array $values
     * @return bool
     */
    private static function shouldRenderOptionalSegment($inner, array $values)
    {
        if (preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]*)?\}/', $inner, $matches)) {
            foreach ($matches[1] as $name) {
                if (isset($values[$name]) && (string) $values[$name] !== '') {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 从 $start（指向 $open 字符）开始跳过平衡括号段，返回 $close 之后的位置。
     * 未找到匹配的 $close 时返回 $start（由调用方决定如何处理）。
     *
     * @param string $s
     * @param int $start
     * @param string $open
     * @param string $close
     * @return int
     */
    private static function skipBalancedBrackets($s, $start, $open, $close)
    {
        $len = strlen($s);
        $depth = 1;
        $j = $start + 1;
        while ($j < $len && $depth > 0) {
            if ($s[$j] === $open) {
                $depth++;
            } elseif ($s[$j] === $close) {
                $depth--;
            }
            $j++;
        }
        return ($depth === 0) ? $j : $start;
    }
}
