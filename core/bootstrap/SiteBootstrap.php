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

namespace Dou\Core\Bootstrap;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Container\Container;
use Dou\Core\Service\Config\SiteConfigAssembler;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 站点配置启动装配器。
 *
 * 从容器解析 {@see SiteConfigAssembler}，把站点配置与 parameter 装配后
 * 写入 Config 门面。本类不持有状态，调用方在合适阶段调用 {@see self::loadSite()} 与
 * {@see self::loadParameter()} 即可。
 */
class SiteBootstrap
{
    /**
     * 装配站点配置并写入 Config::set('site', ...)。
     *
     * 当前请求根 URL 由 shell 层 Init 显式传入（从 `Request::baseUrl()` 取好），
     * 当前语言由 SiteConfigAssembler 内部从 Locale 单例读取。
     *
     * @param bool $publicFormatting 是否在站点配置中应用对外格式化（前台/API 传 true，后台 false）
     * @param string $rootUrl 当前请求根 URL；DB.config.domain 为空时作 fallback
     * @return array 已写入 Config 的 site 配置数组
     */
    public static function loadSite($publicFormatting, $rootUrl = '')
    {
        /** @var SiteConfigAssembler $assembler */
        $assembler = Container::getInstance()->make(SiteConfigAssembler::class);
        $site = $assembler->assemble((bool) $publicFormatting, (string) $rootUrl);
        Config::set('site', $site);

        return $site;
    }

    /**
     * 读取 parameter 表并写入 Config::set('param', ...)。
     *
     * @return array 已写入 Config 的 param 数组
     */
    public static function loadParameter()
    {
        /** @var SiteConfigAssembler $assembler */
        $assembler = Container::getInstance()->make(SiteConfigAssembler::class);
        $param = $assembler->parameter();
        Config::set('param', $param);

        return $param;
    }
}
