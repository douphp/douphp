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
use Dou\Core\Web\Manifest\ManifestCacheGeneration;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 将 RouteManifest 具名路由导出为浏览器端 {@see JsRouteBuilder} / route.js 消费的 payload。
 */
class JsRouteExporter
{
    /**
     * 导出指定端的 name => pattern 映射。
     *
     * @param string $endNamespace 'Admin' | 'Front'
     * @return array<string, string>
     */
    public static function exportRoutesForEnd($endNamespace)
    {
        $endNamespace = (string) $endNamespace;
        $routes = array();

        foreach (RouteManifest::getEntriesByType('declared') as $entry) {
            if ($entry->endNamespace() !== $endNamespace) {
                continue;
            }
            if ($entry->name === null || $entry->name === '') {
                continue;
            }
            $routes[(string) $entry->name] = (string) $entry->pattern;
        }

        ksort($routes);

        return $routes;
    }

    /**
     * 后台 shell 运行时配置（不含路由表，供 head 内联 window.__douRouteConfig）。
     *
     * @return array
     */
    public static function buildAdminConfig()
    {
        return array(
            'shell' => 'admin',
            'url' => defined('ADMIN_URL') ? (string) ADMIN_URL : '',
            'rewrite' => (bool) Config::get('system.admin_rewrite', false),
        );
    }

    /**
     * 后台 shell 路由 payload（config + 路由表，供 JsRouteBuilder / roundtrip 离线断言）。
     *
     * @return array
     */
    public static function buildAdminPayload()
    {
        return self::buildAdminConfig() + array(
            'routes' => self::exportRoutesForEnd('Admin'),
        );
    }

    /**
     * 前台 shell 运行时配置（含当前语言快照，不含路由表）。
     *
     * @return array
     */
    public static function buildFrontConfig()
    {
        $site = Config::get('site', array());
        $lang = array();
        if (function_exists('locale')) {
            $lang = locale()->toArray();
        }

        return array(
            'shell' => 'front',
            'url' => isset($site['root_url']) ? (string) $site['root_url'] : '',
            'rewrite' => (bool) Config::get('site.rewrite', false),
            'lang' => array(
                'mode' => isset($lang['mode']) ? (string) $lang['mode'] : '',
                'sign' => isset($lang['sign']) ? (string) $lang['sign'] : '',
                'pack' => isset($lang['pack']) ? (string) $lang['pack'] : '',
            ),
        );
    }

    /**
     * 前台 shell 路由 payload（config + 路由表，供 JsRouteBuilder / roundtrip 离线断言）。
     *
     * @return array
     */
    public static function buildFrontPayload()
    {
        return self::buildFrontConfig() + array(
            'routes' => self::exportRoutesForEnd('Front'),
        );
    }

    /**
     * 将运行时配置编码为可嵌入 HTML script 标签的 JSON 字面量。
     *
     * @param array $payload
     * @return string
     */
    public static function toJsonScript(array $payload)
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return $json !== false ? $json : '{}';
    }

    /**
     * 指定端路由表的 JSON 字面量（外部 manifest 脚本与缓存文件共用）。
     *
     * @param string $endNamespace 'Admin' | 'Front'
     * @return string
     */
    public static function manifestJson($endNamespace)
    {
        $json = json_encode(
            self::exportRoutesForEnd($endNamespace),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
        );

        return $json !== false ? $json : '{}';
    }

    /**
     * 指定端路由表的内容指纹（用于 ETag；HTML ?v= 见 manifestUrlVersion）。
     *
     * @param string $endNamespace
     * @return string
     */
    public static function manifestHash($endNamespace)
    {
        return substr(md5(self::manifestJson($endNamespace)), 0, 12);
    }

    /**
     * Web 端 script src 的 ?v= 查询参数（内容指纹 + 清空世代）。
     *
     * @param string $endNamespace
     * @return string
     */
    public static function manifestUrlVersion($endNamespace)
    {
        return ManifestCacheGeneration::urlVersion(self::manifestHash($endNamespace));
    }

    /**
     * 生成可作 <script src> 直接执行的 manifest JS（window.__douRouteManifest = {...};）。
     *
     * 命中 storage/cache/js 内部缓存则直接复用；缺失则现算并落盘。返回 JS 字符串，
     * 由各端 routes_js 端点以 application/javascript 输出（不暴露 storage 直链）。
     *
     * @param string $endNamespace
     * @return string
     */
    public static function ensureCachedManifest($endNamespace)
    {
        $json = self::manifestJson($endNamespace);
        $js = 'window.__douRouteManifest = ' . $json . ';' . "\n";

        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($dir . strtolower($endNamespace) . '-routes.js', $js, LOCK_EX);

        return $js;
    }

    /**
     * 删除所有已缓存的 manifest 文件（清缓存链路调用）。
     *
     * @return void
     */
    public static function clearCachedManifests()
    {
        foreach ((array) glob(self::cacheDir() . '*-routes*.js') as $file) {
            @unlink($file);
        }
    }

    /**
     * manifest 内部缓存目录（storage/cache/js）。
     *
     * @return string
     */
    private static function cacheDir()
    {
        $base = defined('STORAGE_PATH') ? STORAGE_PATH : (defined('ROOT_PATH') ? ROOT_PATH . 'storage/' : '');

        return $base . 'cache' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR;
    }
}
