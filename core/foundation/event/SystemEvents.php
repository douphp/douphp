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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 系统级事件名常量。
 */
class SystemEvents
{
    /** 后台账号密码登录成功（会话已写入） */
    const ADMIN_LOGIN_SUCCESS = 'admin.login.success';

    /** 后台将售后服务单处理完成（状态已改为已处理） */
    const AFTERSALE_HANDLED = 'aftersale.handled';

    /**
     * 订单支付后「站点订单通知邮箱」已成功发送
     * （由 {@see \Dou\Front\Service\Mail\SiteMail::sendOrderPaymentToSite()} 在 `Mail::sendMail` 成功后触发）。
     * 用于额外抄送等扩展，不改变原收件人 logic。
     */
    const ORDER_PAYMENT_SITE_MAIL_SENT = 'order.payment.site_mail_sent';
}
