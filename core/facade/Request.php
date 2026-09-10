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
 * Request 静态门面：底层为 {@see \Dou\Core\Web\Http\Request} 容器单例。
 *
 * 与 helper `request()` 等价。
 *
 * @method static array all()
 * @method static mixed input(string $key, $default = null)
 * @method static mixed query(string $key, $default = null)
 * @method static mixed get($key = null, $default = null)
 * @method static mixed post($key = null, $default = null)
 * @method static mixed cookie(string $key, $default = null)
 * @method static mixed server(string $key, $default = null)
 * @method static array only(array $keys)
 * @method static array except(array $keys)
 * @method static bool has(string $key)
 * @method static bool filled(string $key)
 * @method static bool boolean(string $key, bool $default = false)
 * @method static int integer(string $key, int $default = 0)
 * @method static string method()
 * @method static bool isMethod(string $method)
 * @method static bool isAjax()
 * @method static string header(string $name, $default = null)
 * @method static array headers()
 * @method static string ip()
 * @method static string userAgent()
 * @method static string path()
 * @method static bool isSecure()
 * @method static string scheme()
 * @method static string host()
 * @method static string url()
 * @method static string fullUrl()
 * @method static mixed file($key = null)
 * @method static array validate(array $rules, array $messages = array(), bool $collectAll = false)
 * @method static void setRoute(string $module, string $action, string $sub = '')
 * @method static string routeModule()
 * @method static string routeAction()
 * @method static string routeSub()
 * @method static void setBaseUrl(string $url)
 * @method static string baseUrl()
 */
class Request extends StaticFacade
{
    /**
     * 容器中以底层 Request FQCN 为 key 注册。
     *
     * @return string
     */
    protected static function getAccessor()
    {
        return \Dou\Core\Web\Http\Request::class;
    }
}
