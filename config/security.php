<?php

/**
 * DouPHP
 * ------------------------------------------------------------------------------------
 * 版权所有 2013-2026 漳州豆壳网络科技有限公司，并保留所有权利。
 * 网站地址：http://www.douphp.com
 * ------------------------------------------------------------------------------------
 * 这不是一个自由软件！您只能在遵守授权协议前提下对程序代码进行修改和使用；
 * 不允许对程序代码以任何形式任何目的的再发布。
 * 授权协议：http://www.douphp.com/license.html
 * ------------------------------------------------------------------------------------
 * Author: DouCo Co.,Ltd.
 * Release Date: 2026-09-04
 */

/**
 * 安全栈配置（由 Init 早期合并入 Config）
 *
 * 由 {@see \Dou\Core\Init\InitTrait::loadSecurityConfig()} 在 instantiateCommonFactories
 * （构造 AuditService($request->ip()) 之前）灌入 Config，并据此 Request::setTrustedProxies()，
 * 保证可信代理判定先于任何 ip() 调用生效。
 *
 * 配置块：
 *   trusted_proxies - 可信反向代理名单（精确 IP 或 CIDR）。空 = 不信任任何代理：
 *                     Request::ip() 仅用 REMOTE_ADDR，X-Forwarded-* 一律忽略。
 *                     部署在负载均衡 / Nginx 反代后时，把代理出口 IP 配进来才采信转发头。
 *   headers         - 基线安全响应头（无 CSP；由三端 SecurityHeadersMiddleware 下发）：
 *                     frame_options          - X-Frame-Options（SAMEORIGIN / DENY；空串关闭）
 *                     content_type_options   - true 时下发 X-Content-Type-Options: nosniff
 *                     referrer_policy        - Referrer-Policy（空串关闭）
 *                     permissions_policy     - Permissions-Policy（空串关闭）
 *                     hsts                   - HTTP 严格传输安全，仅 HTTPS 且 enabled 时下发：
 *                                                enabled / max_age（秒）/ subdomains（含子域）
 *   throttle        - 定向限流（targeted）：
 *                     store   - 文件后端目录（ThrottleStore 落 <hash>.json）
 *                     default - 全局默认限流（null = 默认不限流，仅敏感端点在中间件 throttleFor 配额）
 *   session         - 会话 Cookie 硬化（由 InitTrait::startSession() 在 session_start() 前应用）：
 *                     httponly        - 会话 Cookie 禁止 JS 读取（防 XSS 窃取 sid），建议恒 true
 *                     secure          - 仅 HTTPS 下发；null = 跟随运行时 IS_HTTPS（混合部署推荐）
 *                     samesite        - SameSite 策略（Lax / Strict / None），跨站 CSRF 防御
 *                     use_strict_mode - 拒绝未初始化的外部 sid（防会话固定），建议恒 true
 */
if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

return [
    'security' => [
        // 默认不信任任何代理（trust-none）。示例：['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']
        'trusted_proxies' => [],

        'headers' => [
            'frame_options' => 'SAMEORIGIN',
            'content_type_options' => true,
            'referrer_policy' => 'strict-origin-when-cross-origin',
            'permissions_policy' => 'geolocation=(), microphone=(), camera=()',
            'hsts' => [
                'enabled' => false,
                'max_age' => 31536000,
                'subdomains' => false,
            ],
        ],

        'throttle' => [
            'store' => STORAGE_PATH . 'cache/throttle/',
            'default' => null,
        ],

        'session' => [
            'httponly' => true,
            // null = 跟随运行时 IS_HTTPS；true/false 可强制。
            'secure' => null,
            'samesite' => 'Lax',
            'use_strict_mode' => true,
        ],
    ],
];
