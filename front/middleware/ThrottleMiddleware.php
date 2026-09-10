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

use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Middleware\AbstractThrottleMiddleware;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台定向限流中间件。
 *
 * 仅对敏感端点按 IP 限流（与登录失败限流 LoginService::ipRateLimited 独立、互补）：
 * 登录 / 注册 / 手机号登录 / 找回密码 / 短信验证码下发 / 公共表单提交。其余路由不限流。
 */
class ThrottleMiddleware extends AbstractThrottleMiddleware
{
    /**
     * 路由键 → 配额（max 次 / window 秒）。
     *
     * @var array
     */
    private static $limits = array(
        'captcha' => array('max' => 30, 'window' => 60),
        'user/login_post' => array('max' => 5, 'window' => 60),
        'user/login_phone_post' => array('max' => 5, 'window' => 60),
        'user/register_post' => array('max' => 5, 'window' => 60),
        'user/password_reset_post' => array('max' => 5, 'window' => 300),
        'captcha/verification' => array('max' => 5, 'window' => 300),
        'guestbook/store' => array('max' => 10, 'window' => 60),
        'landing/submit' => array('max' => 10, 'window' => 60),
        'consultation/store' => array('max' => 10, 'window' => 60),
        'distribution/apply_post' => array('max' => 10, 'window' => 300),
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
     * 超限：提示后跳首页（前台错误 UX，与 DomainException 渲染一致）。
     *
     * @param int $retryAfter
     * @return void
     */
    protected function reject($retryAfter)
    {
        if (!headers_sent()) {
            header('Retry-After: ' . (int) $retryAfter);
        }
        $msg = lang_has('request_throttled') ? (string) lang('request_throttled') : (string) lang('illegal');

        throw new DomainException($msg, HOME_URL);
    }
}
