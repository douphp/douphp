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

namespace Dou\Front\Middleware;

use Dou\Core\Foundation\Middleware\AbstractSecurityHeadersMiddleware;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台基线安全响应头中间件（薄壳，行为见基类）。
 */
class SecurityHeadersMiddleware extends AbstractSecurityHeadersMiddleware
{
}
