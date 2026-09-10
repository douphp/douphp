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

namespace Dou\Front\Foundation\Routing;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台 HTTP 边界路由预处理（仅前台 index.php 调用）
 *
 * 在 Init::boot 之前从原始 route 串剥出多语言前缀（如 zh-cn），把语言前缀与已剥前缀的路由串分别
 * 交给 Request 承载（setRouteLangSign / setRouteString）。Init::resolveCurLang 只消费 Request
 * 上的语言前缀；首页由 routeString === '' 派生，无需单独存 is_home。
 *
 * 纯函数级工具：不依赖 Config / Locale / 容器，可在 Init 之前安全运行。admin / api 无 URL 语言
 * 前缀，不调用本类。
 */
class LangPrefixParser
{
    /**
     * 剥前台 route 串的多语言前缀。
     *
     * @param string $rawRoute 原始 route（通常取自 $_GET['route']）
     * @return array {langSign: string, routeString: string} langSign 无前缀时为空串
     */
    public static function parse($rawRoute)
    {
        $route = (string) $rawRoute;
        $langSign = '';

        if ($route !== '') {
            $parts = explode('/', trim($route, '/'));
            if (!empty($parts[0]) && preg_match('/^[a-z]{2}-[a-z]{2}$/i', $parts[0])) {
                $langSign = array_shift($parts);
                $route = implode('/', $parts);
            }
        }

        return array(
            'langSign' => $langSign,
            'routeString' => $route,
        );
    }
}
