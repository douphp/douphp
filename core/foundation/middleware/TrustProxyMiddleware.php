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

namespace Dou\Core\Foundation\Middleware;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Web\Http\Request;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 可信代理中间件（三端共用，管道最前置）。
 *
 * 真正的可信代理判定已在 Init 早期 {@see \Dou\Core\Init\InitTrait::loadSecurityConfig()}
 * 生效（因 Request::ip() 在管道之前就被 AuditService / 续登读取）。本中间件只是**幂等**地
 * 再断言一次 `security.trusted_proxies`，保证即便某端 Init 顺序异常也不致漏设，且语义集中可读。
 *
 * 默认 trusted_proxies 为空 = 不信任任何代理，Request::ip() 仅取 REMOTE_ADDR。
 */
class TrustProxyMiddleware implements MiddlewareInterface
{
    /**
     * @param callable $next
     * @return mixed
     */
    public function handle($next)
    {
        $proxies = Config::get('security.trusted_proxies', array());
        Request::setTrustedProxies(is_array($proxies) ? $proxies : array());

        return $next();
    }
}
