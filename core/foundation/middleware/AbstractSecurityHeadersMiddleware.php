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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 基线安全响应头中间件基类。
 *
 * 据 config/security.php 的 `security.headers` 在管道最前置下发一组基线安全头
 * （无 CSP）：X-Content-Type-Options / X-Frame-Options / Referrer-Policy / Permissions-Policy；
 * HSTS 仅在 HTTPS（{@see \Dou\Core\Web\Http\Request::isSecure()}）且 config 显式开启时下发。
 *
 * 仅覆盖已匹配路由（中间件管道仅对命中路由运行；404 由 Router 自渲染，不在覆盖面内）。
 * 三端薄壳子类继承本类即可，行为一致。
 *
 * 注：中间件工作在 HTTP 边界，可使用 request() helper。
 */
abstract class AbstractSecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * @param callable $next
     * @return mixed
     */
    public function handle($next)
    {
        $headers = Config::get('security.headers', array());
        if (is_array($headers) && !headers_sent()) {
            $this->sendHeaders($headers);
        }

        return $next();
    }

    /**
     * 据配置下发基线安全头。
     *
     * @param array $headers
     * @return void
     */
    private function sendHeaders(array $headers)
    {
        if (!empty($headers['content_type_options'])) {
            header('X-Content-Type-Options: nosniff');
        }
        if (!empty($headers['frame_options'])) {
            header('X-Frame-Options: ' . $headers['frame_options']);
        }
        if (!empty($headers['referrer_policy'])) {
            header('Referrer-Policy: ' . $headers['referrer_policy']);
        }
        if (!empty($headers['permissions_policy'])) {
            header('Permissions-Policy: ' . $headers['permissions_policy']);
        }

        if (isset($headers['hsts']) && is_array($headers['hsts'])
            && !empty($headers['hsts']['enabled']) && request()->isSecure()) {
            $maxAge = isset($headers['hsts']['max_age']) ? (int) $headers['hsts']['max_age'] : 0;
            $value = 'max-age=' . $maxAge;
            if (!empty($headers['hsts']['subdomains'])) {
                $value .= '; includeSubDomains';
            }
            header('Strict-Transport-Security: ' . $value);
        }
    }
}
