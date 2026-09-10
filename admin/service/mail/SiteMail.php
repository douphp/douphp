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

namespace Dou\Admin\Service\Mail;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Vendor\Mail\Mail;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台站点邮件集中发送服务。
 *
 * 负责后台账号相关邮件，以及 `MailNotificationRegistrar` 中后台相关
 * 事件（登录提醒 / 售后处理通知）的监听处理。
 */
class SiteMail
{
    /**
     * 后台管理员忘记密码：发送找回密码邮件。
     *
     * @param string $toEmail 收件人邮箱（管理员的注册邮箱）。
     * @param array $lang 当前语言包。
     * @param array $user 管理员行（至少包含 `username`）。
     * @param string $resetUrlBodySegment 正文中的重置链接片段（完整 URL）。
     * @return bool 是否成功送达 SMTP/mail。
     */
    public static function sendAdminPasswordResetMail($toEmail, array $lang, array $user, $resetUrlBodySegment)
    {
        if ($toEmail === '' || $toEmail === null) {
            return false;
        }

        $siteUrl = rtrim(ROOT_URL, '/');
        $username = isset($user['username']) ? $user['username'] : '';

        $body = $username . (isset($lang['login_password_reset_body_0']) ? $lang['login_password_reset_body_0'] : '');
        $body .= $resetUrlBodySegment . (isset($lang['login_password_reset_body_1']) ? $lang['login_password_reset_body_1'] : '');
        $body .= Config::get('site.site_name', '') . '. ' . $siteUrl;

        $subject = isset($lang['login_password_reset']) ? $lang['login_password_reset'] : '';
        $altBody = isset($lang['mail_altbody']) ? $lang['mail_altbody'] : '';

        return (bool) self::deliver($toEmail, $subject, $body, $altBody);
    }

    /**
     * 后台账号登录成功提醒（事件监听）。
     *
     * 由 `param.mail_notify_admin_login` 控制启用；启用后向 `site.email` 发送一封提醒邮件。
     *
     * @param array $payload 事件 payload：`admin`（管理员行）、`ip`、`lang`。
     * @return void
     */
    public static function onAdminLoginSuccess($payload)
    {
        if (!Config::get('param.mail_notify_admin_login', false)) {
            return;
        }
        if (!is_array($payload)) {
            return;
        }

        $mailto = trim(Config::get('site.email', ''));
        if ($mailto === '') {
            return;
        }

        $admin = isset($payload['admin']) && is_array($payload['admin']) ? $payload['admin'] : array();
        $username = isset($admin['username']) ? $admin['username'] : '';
        $ip = isset($payload['ip']) ? $payload['ip'] : '';

        $subject = '后台登录提醒';
        $body = '管理员：' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '<br/>';
        $body .= '时间：' . date('Y-m-d H:i:s') . '<br/>';
        $body .= 'IP：' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');

        $altBody = strip_tags(str_replace('<br/>', "\n", $body));
        $langAlt = '';
        if (isset($payload['lang']) && is_array($payload['lang']) && isset($payload['lang']['mail_altbody'])) {
            $langAlt = $payload['lang']['mail_altbody'];
        }

        self::deliver($mailto, $subject, $body, $langAlt !== '' ? $langAlt : $altBody);
    }

    /**
     * 售后服务处理完成：通知用户（事件监听）。
     *
     * 由 `param.mail_notify_aftersale_handled` 控制启用；启用且 payload 含合法
     * `user_email` 时，向用户发送售后处理通知。
     *
     * @param array $payload 事件 payload：`order_sn`、`user_email`、`lang`、`aftersale_money` 等。
     * @return void
     */
    public static function onAftersaleHandled($payload)
    {
        if (!Config::get('param.mail_notify_aftersale_handled', false)) {
            return;
        }
        if (!is_array($payload)) {
            return;
        }

        $userEmail = isset($payload['user_email']) ? trim($payload['user_email']) : '';
        if ($userEmail === '' || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $orderSn = isset($payload['order_sn']) ? $payload['order_sn'] : '';
        $money = isset($payload['aftersale_money']) ? $payload['aftersale_money'] : '';

        $subject = '售后服务进度通知';
        $lang = isset($payload['lang']) && is_array($payload['lang']) ? $payload['lang'] : array();
        if (!empty($lang['aftersale'])) {
            $subject = $lang['aftersale'] . ' — ' . $subject;
        }

        $body = '您的订单（单号：' . htmlspecialchars($orderSn, ENT_QUOTES, 'UTF-8') . '）相关售后服务已处理。<br/>';
        if ($money !== '') {
            $body .= '退款金额：' . htmlspecialchars($money, ENT_QUOTES, 'UTF-8') . '<br/>';
        }

        $altBody = strip_tags(str_replace('<br/>', "\n", $body));
        $langAlt = isset($lang['mail_altbody']) ? $lang['mail_altbody'] : $altBody;

        self::deliver($userEmail, $subject, $body, $langAlt);
    }

    /**
     * 统一调用 `Mail::sendMail`，便于日后接入日志/队列。
     *
     * @param mixed $to 收件人。
     * @param string $subject 主题。
     * @param string $body 正文（HTML）。
     * @param string $altBody 纯文本备用正文。
     * @return string|null `Mail::sendMail` 的返回值（success 或 null）。
     */
    private static function deliver($to, $subject, $body, $altBody)
    {
        return Mail::sendMail($to, $subject, $body, $altBody);
    }
}
