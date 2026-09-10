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
use Dou\Core\Support\Naming;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 短地址模块开关与路径工具（只读）
 *
 * 站点开启 site.short_url_module 且对应 features.<short> 也为 true 时，
 * 该模块的 URL 省略模块名段。
 *   例：item/fenleiyi → fenleiyi；item/fenleiyi/1932.html → fenleiyi/1932.html
 *
 * 调用面：
 *   - PrettyRouteMatcher：解析前拦截带前缀的 URL，并在无规则命中时补回前缀重试。
 *   - UrlBuilder：生成路径时判别是否短链模块、按需移除模块前缀。
 *   - getSlugPath()：短链兜底返回纯 slug，不返回 module 字面量。
 */
class ShortUrlPolicy
{
    /** @var string|null 缓存解析结果：null=未读取，'' 表示未启用，否则为模块名 */
    private static $resolved = null;

    /**
     * 清空进程缓存（单测或配置热更后调用）
     *
     * @return void
     */
    public static function clearCache()
    {
        self::$resolved = null;
    }

    /**
     * 当前生效的短地址模块名；未启用返回空串
     *
     * @return string
     */
    public static function module()
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $short = (string) Config::get('site.short_url_module', '');
        if ($short !== '' && Config::get('features.' . $short, false)) {
            self::$resolved = $short;
        } else {
            self::$resolved = '';
        }
        return self::$resolved;
    }

    /**
     * 短地址功能是否启用
     *
     * @return bool
     */
    public static function enabled()
    {
        return self::module() !== '';
    }

    /**
     * 当前短地址模块的 URL 形式模块名（article → news；其它原样）
     *
     * @return string
     */
    public static function urlModule()
    {
        $short = self::module();
        if ($short === '') {
            return '';
        }
        return Naming::urlModule($short);
    }

    /**
     * 指定数据库模块名是否为当前短地址模块
     *
     * @param string $baseModule
     * @return bool
     */
    public static function isShort($baseModule)
    {
        $short = self::module();
        return $short !== '' && $baseModule === $short;
    }

    /**
     * 路径是否以短地址模块前缀开头（item/... 或 news/...）。
     *
     * 启用短地址后此类路径应当被拒，避免长短 URL 并存。
     *
     * @param string $route 已去掉首尾 / 的路径
     * @return bool
     */
    public static function isPrefixedRoute($route)
    {
        $short = self::module();
        if ($short === '') {
            return false;
        }

        $prefixes = array($short);
        if ($short === 'article') {
            $prefixes[] = 'news';
        }

        foreach ($prefixes as $prefix) {
            if ($route === $prefix || strpos($route, $prefix . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 给短地址路径补回 URL 模块前缀（供解析端重试用）。
     *
     * 未启用短地址返回空串，调用方据此决定是否重试。
     *
     * @param string $route 已去掉首尾 / 的路径
     * @return string
     */
    public static function prefixRoute($route)
    {
        $urlModule = self::urlModule();
        if ($urlModule === '') {
            return '';
        }
        return $urlModule . '/' . $route;
    }

    /**
     * 移除生成路径开头的 URL 模块前缀（仅当 baseModule 为当前短地址模块时生效）。
     *
     * @param string $path
     * @param string $urlModule URL 展示用模块名（如 article → news）
     * @param string $baseModule 数据库模块名
     * @return string
     */
    public static function stripModulePrefix($path, $urlModule, $baseModule)
    {
        if (!self::isShort($baseModule) || $urlModule === '') {
            return $path;
        }
        $prefix = $urlModule . '/';
        if (strpos($path, $prefix) === 0) {
            return substr($path, strlen($prefix));
        }
        return $path;
    }
}
