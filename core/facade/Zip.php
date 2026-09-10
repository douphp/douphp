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
 * Zip 静态门面：底层为 {@see \Dou\Core\Support\Zip} 容器单例。
 *
 * @method static bool extract(string $zipPath, string $destinationDir)
 * @method static bool create(string $zipPath, array $paths, string $removePath, string &$errorInfo = '')
 */
class Zip extends StaticFacade
{
    /**
     * 容器中以底层 Zip FQCN 为 key 注册。
     *
     * @return string
     */
    protected static function getAccessor()
    {
        return \Dou\Core\Support\Zip::class;
    }
}
