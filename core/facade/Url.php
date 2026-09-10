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
use Dou\Core\Web\Routing\UrlGenerator;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * Url 静态门面：底层为 {@see UrlGenerator} 容器单例。
 *
 * 业务代码生成站点 URL 推荐用 helper `route('module.action', $params, $options)` 短写法
 * 直接拿 URL 字符串；本门面承担非 URL 短写法的 instance 方法
 * （urlMini / warmupUrlCache / getSlugPath）。
 *
 * @method static string url(string $route, array $params = array(), array $options = array())
 * @method static string urlMini(string $module, string $value = '', bool $tabbar = false)
 * @method static string getSlugPath(string $module, $id, string $mode = 'full')
 * @method static void warmupUrlCache(string $module, array $rows)
 */
class Url extends StaticFacade
{
    /**
     * 容器中以 UrlGenerator FQCN 为 key 注册。
     *
     * @return string
     */
    protected static function getAccessor()
    {
        return UrlGenerator::class;
    }
}
