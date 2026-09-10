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

use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 浏览器 route.js 的 PHP 镜像，供 roundtrip 扫描与离线断言。
 *
 * 逻辑须与 admin/view/js/route.js、theme/default/js/route.js 保持语义一致。
 */
class JsRouteBuilder
{
    /**
     * 由 payload + 路由名生成完整 URL。
     *
     * @param array $payload JsRouteExporter 产物
     * @param string $name 点分路由名
     * @param array $params 具名路径参数
     * @param array $options page / query
     * @return string
     */
    public static function url(array $payload, $name, array $params = array(), array $options = array())
    {
        $name = (string) $name;
        $routes = isset($payload['routes']) && is_array($payload['routes']) ? $payload['routes'] : array();
        if (!isset($routes[$name])) {
            throw new \InvalidArgumentException('Unknown route: ' . $name);
        }

        $pattern = (string) $routes[$name];
        $placeholderNames = self::placeholderNames($pattern);
        $values = array();
        foreach ($placeholderNames as $placeholderName) {
            if (array_key_exists($placeholderName, $params)) {
                $values[$placeholderName] = (string) $params[$placeholderName];
            }
        }

        $path = PrettyUrlCompiler::fill($pattern, $values);
        $rewrite = !empty($payload['rewrite']);
        $inner = $rewrite ? $path : ('index.php?route=' . $path);

        $shell = isset($payload['shell']) ? (string) $payload['shell'] : '';
        if ($shell === 'front') {
            $url = self::applyFrontLanguagePrefix($inner, $payload);
        } else {
            $base = isset($payload['url']) ? (string) $payload['url'] : '';
            $url = $base . $inner;
        }

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
     * 从 pattern 提取占位符名列表。
     *
     * @param string $pattern
     * @return array<int, string>
     */
    public static function placeholderNames($pattern)
    {
        $names = array();
        if (preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]*)?\}/', (string) $pattern, $matches)) {
            foreach ($matches[1] as $name) {
                if (!in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * 前台语言前缀（对齐 UrlBuilder::applyLanguagePrefix）。
     *
     * @param string $path
     * @param array $payload
     * @return string
     */
    private static function applyFrontLanguagePrefix($path, array $payload)
    {
        $base = isset($payload['url']) ? (string) $payload['url'] : '';
        $lang = isset($payload['lang']) && is_array($payload['lang']) ? $payload['lang'] : array();
        $pack = isset($lang['pack']) ? (string) $lang['pack'] : '';

        if ($pack === '') {
            return $base . $path;
        }

        $mode = isset($lang['mode']) ? (string) $lang['mode'] : '';
        if ($mode === 'rewrite_open') {
            $sign = isset($lang['sign']) ? (string) $lang['sign'] : '';
            return $base . $sign . '/' . $path;
        }

        return Util::normalizeQueryString($base . $path . '&lang=' . $pack);
    }
}
