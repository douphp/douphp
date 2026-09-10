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

namespace Dou\Core\Foundation\Event;

use Dou\Admin\Service\Mail\SiteMail as AdminSiteMail;
use Dou\Front\Service\Mail\SiteMail as FrontSiteMail;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 可选邮件通知：通过站点参数开关启用，默认不产生任何额外邮件。
 *
 * 仅负责将系统事件桥接到 `Dou\Front\Service\Mail\SiteMail` 与
 * `Dou\Admin\Service\Mail\SiteMail` 上的静态处理方法；具体收件人、正文与
 * 开关逻辑由 SiteMail 内部维护。
 *
 * 需在 bootstrap 中调用一次 {@see register()}。
 */
class MailNotificationRegistrar
{
    /** @var bool */
    private static $registered = false;

    /**
     * 注册监听（幂等，多次调用无效）。
     *
     * @return void
     */
    public static function register()
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        Event::listen(SystemEvents::ADMIN_LOGIN_SUCCESS, array(AdminSiteMail::class, 'onAdminLoginSuccess'), 0);
        Event::listen(SystemEvents::AFTERSALE_HANDLED, array(AdminSiteMail::class, 'onAftersaleHandled'), 0);
        Event::listen(SystemEvents::ORDER_PAYMENT_SITE_MAIL_SENT, array(FrontSiteMail::class, 'onOrderPaymentSiteMailSent'), 0);
    }
}
