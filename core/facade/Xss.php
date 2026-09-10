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

namespace Dou\Core\Facade;

use Dou\Core\Foundation\Facade\StaticFacade;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * Xss 静态门面：底层为 {@see \Dou\Core\Infra\Security\Xss} 容器单例。
 *
 * 与 helper `xss()` 等价。
 *
 * @method static \Dou\Core\Infra\Security\Xss setConfig(array $config)
 * @method static mixed getConfig(string $key = '')
 * @method static \Dou\Core\Infra\Security\Xss resetConfig()
 * @method static string filterHtml(string $html, array $config = array())
 * @method static string comment(string $html)
 * @method static string content(string $html)
 * @method static string rss(string $html)
 * @method static string plain(string $html)
 * @method static string text($value)
 * @method static array|string post($value)
 */
class Xss extends StaticFacade
{
    /**
     * 容器中以底层 Xss FQCN 为 key 注册。
     *
     * @return string
     */
    protected static function getAccessor()
    {
        return \Dou\Core\Infra\Security\Xss::class;
    }
}
