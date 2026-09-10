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

use Dou\Admin\Http\AdminMessageResponder;
use Dou\Core\Contract\MessageResponderInterface;
use Dou\Core\Foundation\Facade\StaticFacade;
use Dou\Core\Web\Http\Response;
use Dou\Front\Http\FrontMessageResponder;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * Message 静态门面：底层为容器中以 {@see MessageResponderInterface} 为 key 注册的端实现
 * （前台 {@see FrontMessageResponder} / 后台 {@see AdminMessageResponder} / API 走 NullMessageResponder）。
 *
 * 与 helper `message()` 等价。
 *
 * @method static Response respond(string $text = '', string $url = '', string $out = '', $time = 3, string $check = '', string $btnValue = '')
 */
class Message extends StaticFacade
{
    /**
     * 容器中以 MessageResponderInterface FQCN 为 key 注册。
     *
     * @return string
     */
    protected static function getAccessor()
    {
        return MessageResponderInterface::class;
    }
}
