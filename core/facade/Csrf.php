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

use Dou\Core\Foundation\Csrf\CsrfManager;
use Dou\Core\Foundation\Facade\StaticFacade;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * Csrf 静态门面：底层为 {@see CsrfManager} 容器单例。
 *
 * 与 helper `csrf()` 等价。
 *
 * @method static string generate(string $id)
 * @method static mixed token(string $id = 'static_admin')
 * @method static bool verify(string $token, string $id = 'static_admin')
 * @method static bool isOneTime(string $id)
 */
class Csrf extends StaticFacade
{
    /**
     * 容器中以 CsrfManager FQCN 为 key 注册。
     *
     * @return string
     */
    protected static function getAccessor()
    {
        return CsrfManager::class;
    }
}
