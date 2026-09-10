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

namespace Dou\Api\Middleware;

use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Middleware\AbstractThrottleMiddleware;
use Dou\Core\Web\Http\ApiResponse;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * API 端定向限流中间件。
 *
 * 对登录 / 注册 / 手机号登录 / 找回密码 / 短信验证码下发，以及公共匿名写接口（留言 / 咨询 /
 * 邮件订阅）与防伪查询按 IP 限流；超限返回 JSON 429。
 */
class ThrottleMiddleware extends AbstractThrottleMiddleware
{
    /**
     * 路由键 → 配额（max 次 / window 秒）。
     *
     * @var array
     */
    private static $limits = array(
        'user/login_post' => array('max' => 5, 'window' => 60),
        'user/login_phone_post' => array('max' => 5, 'window' => 60),
        'user/register_post' => array('max' => 5, 'window' => 60),
        'user/password_reset_post' => array('max' => 5, 'window' => 300),
        'captcha/verification' => array('max' => 5, 'window' => 300),

        // 公共匿名写接口：与各 controller 业务层 isWaterByIp 互补，前置按 IP 拦高频。
        'guestbook/store' => array('max' => 5, 'window' => 300),
        'consultation/store' => array('max' => 5, 'window' => 300),
        'email/store' => array('max' => 5, 'window' => 300),

        // 防伪码查询放宽（扫多个 SN 的合法场景），但收敛批量枚举。
        'sn/search' => array('max' => 20, 'window' => 60),

        // LLM 成本端点：防滥用刷量。
        'chat/stream' => array('max' => 20, 'window' => 60),
        'chat/new_session' => array('max' => 10, 'window' => 60),
    );

    /**
     * @param string $module
     * @param string $action
     * @param string $sub
     * @return array|null
     */
    protected function throttleFor($module, $action, $sub)
    {
        foreach ($this->candidates($module, $action, $sub) as $candidate) {
            if (isset(self::$limits[$candidate])) {
                return self::$limits[$candidate];
            }
        }

        return null;
    }

    /**
     * 超限：返回 JSON 429 后终止。
     *
     * @param int $retryAfter
     * @return void
     */
    protected function reject($retryAfter)
    {
        if (!headers_sent()) {
            header('Retry-After: ' . (int) $retryAfter);
        }
        $msg = lang_has('request_throttled') ? (string) lang('request_throttled') : 'request_throttled';
        ApiResponse::error(ApiCodes::RATE_LIMITED, $msg, array(), 429)->send();
        exit;
    }
}
