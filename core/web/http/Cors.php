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

namespace Dou\Core\Web\Http;

use Dou\Core\Foundation\Configuration\Config;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * CORS 跨域支持（API 端）
 *
 * 配置：config/security.php 的 security.cors（默认 enabled=false，零行为变化）。
 * 接入：api/foundation/routing/Router::dispatch() 顶部，在路由解析之前执行：
 *   - preflight()：OPTIONS + Access-Control-Request-Method + Origin 命中白名单 →
 *     自行下发全部预检响应头并返回 204 空响应，短路后续路由解析（绕过 404/405）；
 *   - apply()：普通跨域请求 → 命中白名单时下发 Access-Control-Allow-Origin /
 *     Allow-Credentials / Expose-Headers。
 * 因 {@see Response::send()} 的 header 为 append 语义，此处用原生 header() 下发的头
 * 先于响应对象发出，中间件提前 send() 的 401/429 与路由 404/405 JSON 同样携带跨域头。
 * 小程序（wx.request）不发送 Origin，全链路 no-op。
 */
class Cors
{
    /**
     * 普通跨域请求：命中白名单时下发 CORS 响应头。
     *
     * enabled=false 或无 Origin → 完全 no-op；enabled=true 时恒发 Vary: Origin
     * （即使未命中，避免共享缓存把 A 站的响应串给 B 站）。
     *
     * @param Request $request 当前请求
     * @return void
     */
    public static function apply(Request $request)
    {
        $config = self::config();
        if (empty($config['enabled']) || headers_sent()) {
            return;
        }

        header('Vary: Origin', false);

        $origin = self::origin($request);
        if ($origin === '' || !self::matchOrigin($origin, $config)) {
            return;
        }

        header('Access-Control-Allow-Origin: ' . self::allowOriginValue($origin, $config));
        if (!empty($config['credentials'])) {
            header('Access-Control-Allow-Credentials: true');
        }
        $exposeHeaders = array_filter((array) $config['expose_headers'], 'strlen');
        if ($exposeHeaders) {
            header('Access-Control-Expose-Headers: ' . implode(', ', $exposeHeaders));
        }
    }

    /**
     * 预检请求（OPTIONS + Access-Control-Request-Method）：命中时下发全部预检响应头并返回 204。
     *
     * 未开启 / 非预检 / Origin 未命中白名单 → null（维持既有路由行为，如 405 JSON）。
     *
     * @param Request $request 当前请求
     * @return Response|null 命中返回 204 空响应；否则 null
     */
    public static function preflight(Request $request)
    {
        $config = self::config();
        if (empty($config['enabled']) || headers_sent()) {
            return null;
        }
        if (strtoupper((string) $request->method()) !== 'OPTIONS') {
            return null;
        }
        if ((string) $request->header('Access-Control-Request-Method', '') === '') {
            return null;
        }

        $origin = self::origin($request);
        if ($origin === '' || !self::matchOrigin($origin, $config)) {
            return null;
        }

        header('Vary: Origin', false);
        header('Access-Control-Allow-Origin: ' . self::allowOriginValue($origin, $config));
        if (!empty($config['credentials'])) {
            header('Access-Control-Allow-Credentials: true');
        }

        $methods = array_filter((array) $config['allowed_methods'], 'strlen');
        if ($methods) {
            header('Access-Control-Allow-Methods: ' . implode(', ', $methods));
        }
        $allowHeaders = self::negotiateHeaders($request, $config);
        if ($allowHeaders !== '') {
            header('Access-Control-Allow-Headers: ' . $allowHeaders);
        }
        if ((int) $config['max_age'] > 0) {
            header('Access-Control-Max-Age: ' . (int) $config['max_age']);
        }

        return new Response('', 204);
    }

    /**
     * Origin 是否命中白名单。
     *
     * 规则：精确匹配（忽略大小写）/ '*.example.com' 子域通配（按点边界，evil-example.com 不命中）/
     * '*'（credentials=true 时 '*' 不生效，必须显式列 origin）。
     *
     * @param string $origin 请求 Origin
     * @param array  $config cors 配置
     * @return bool
     */
    public static function matchOrigin($origin, array $config)
    {
        $origin = strtolower(trim((string) $origin));
        if ($origin === '') {
            return false;
        }

        foreach ((array) $config['allowed_origins'] as $allowed) {
            $allowed = strtolower(trim((string) $allowed));
            if ($allowed === '') {
                continue;
            }
            if ($allowed === '*') {
                // 携带凭证时浏览器禁止回 '*'，此处直接不放行，须显式列 origin
                return empty($config['credentials']);
            }
            if ($allowed === $origin) {
                return true;
            }
            if (strpos($allowed, '*.') === 0) {
                $suffix = substr($allowed, 1); // '.example.com'
                if (strlen($origin) > strlen($suffix) && substr($origin, -strlen($suffix)) === $suffix) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 预检允许的请求头：请求方列表与配置白名单取交集（回显请求方大小写）。
     *
     * @param Request $request 当前请求
     * @param array   $config  cors 配置
     * @return string 逗号分隔的允许头；请求方未列头时返回空串
     */
    private static function negotiateHeaders(Request $request, array $config)
    {
        $requested = trim((string) $request->header('Access-Control-Request-Headers', ''));
        if ($requested === '') {
            return '';
        }

        $allowed = array();
        foreach ((array) $config['allowed_headers'] as $name) {
            $allowed[] = strtolower(trim((string) $name));
        }

        $out = array();
        foreach (preg_split('/\s*,\s*/', $requested) as $name) {
            if ($name !== '' && in_array(strtolower($name), $allowed, true)) {
                $out[] = $name;
            }
        }

        return implode(', ', $out);
    }

    /**
     * Access-Control-Allow-Origin 的回应值：credentials=false 且白名单含 '*' 时回 '*'，否则回显 origin。
     *
     * @param string $origin 请求 Origin
     * @param array  $config cors 配置
     * @return string
     */
    private static function allowOriginValue($origin, array $config)
    {
        if (empty($config['credentials'])) {
            foreach ((array) $config['allowed_origins'] as $allowed) {
                if (trim((string) $allowed) === '*') {
                    return '*';
                }
            }
        }

        return $origin;
    }

    /**
     * 读取请求 Origin（大小写保持原样，匹配时内部降级比较）。
     *
     * @param Request $request 当前请求
     * @return string
     */
    private static function origin(Request $request)
    {
        return trim((string) $request->header('Origin', ''));
    }

    /**
     * 读取 cors 配置并补齐默认值（config 缺失单项时按默认值运行）。
     *
     * @return array
     */
    private static function config()
    {
        $config = Config::get('security.cors', array());
        if (!is_array($config)) {
            $config = array();
        }

        return array_merge(array(
            'enabled' => false,
            'allowed_origins' => array(),
            'allowed_methods' => array('GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'),
            'allowed_headers' => array('Content-Type', 'Authorization', 'X-HTTP-Method-Override', 'X-Requested-With'),
            'expose_headers' => array('Retry-After'),
            'max_age' => 86400,
            'credentials' => false,
        ), $config);
    }
}
