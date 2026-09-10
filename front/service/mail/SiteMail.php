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

namespace Dou\Front\Service\Mail;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Event\Event;
use Dou\Core\Foundation\Event\SystemEvents;
use Dou\Core\Infra\Database\Connection;
use Dou\Vendor\Mail\Mail;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台站点邮件集中发送服务。
 *
 * 除 `Captcha` 验证码邮件外，前台所有 `Mail::sendMail` 调用均经此入口，
 * 便于日后统一接入日志/队列等基础设施。
 */
class SiteMail
{
    /**
     * 发送「站点订单通知」邮件给 `site.email`，并在成功后触发
     * `SystemEvents::ORDER_PAYMENT_SITE_MAIL_SENT` 供扩展监听。
     *
     * @param Connection $db 数据库连接（用于查询订单行）。
     * @param array $lang 当前语言包。
     * @param mixed $orderSn 订单号。
     * @return bool 是否成功送达 SMTP/mail。
     */
    public static function sendOrderPaymentToSite(Connection $db, array $lang, $orderSn)
    {
        $orderSn = (string) $orderSn;
        if ($orderSn === '') {
            return false;
        }

        $siteEmail = Config::get('site.email', '');
        if ($siteEmail === '') {
            return false;
        }

        $order = $db->table('order')->where('order_sn', $orderSn)->find();
        if (!is_array($order) || empty($order)) {
            return false;
        }

        // 收货联系人字段已迁出 dou_order，统一从 dou_order_address 快照补齐
        $addrRow = $db->table('order_address')->where('order_id', (int) $order['order_id'])->find();
        $order['contact'] = is_array($addrRow) && isset($addrRow['contact_name']) ? $addrRow['contact_name'] : '';
        $order['phone'] = is_array($addrRow) && isset($addrRow['phone']) ? $addrRow['phone'] : '';
        $order['address'] = is_array($addrRow) && isset($addrRow['address']) ? $addrRow['address'] : '';

        $orderCreatedTs = Util::toTimestamp(isset($order['created_at']) ? $order['created_at'] : null);
        $order['created_at'] = $orderCreatedTs !== null ? date('Y-m-d H:i:s', $orderCreatedTs) : '';

        $statusKey = 'order_status_' . (isset($order['status']) ? $order['status'] : '');
        $statusText = isset($lang[$statusKey])
            ? $lang[$statusKey]
            : (isset($order['status']) ? $order['status'] : '');

        $title = (isset($lang['order_success']) ? $lang['order_success'] : '') . '：' . $order['order_sn'];

        $body = (isset($lang['order_created_at']) ? $lang['order_created_at'] : '') . '：' . $order['created_at'] . '<br/>';
        $body .= (isset($lang['order_sn']) ? $lang['order_sn'] : '') . '：' . $order['order_sn'] . '<br/>';
        $body .= (isset($lang['order_order_amount']) ? $lang['order_order_amount'] : '') . '：' . (isset($order['order_amount']) ? $order['order_amount'] : '') . '<br/>';
        $body .= (isset($lang['order_status']) ? $lang['order_status'] : '') . '：' . $statusText . '<br/>';
        $body .= (isset($lang['order_contact']) ? $lang['order_contact'] : '') . '：' . (isset($order['contact']) ? $order['contact'] : '') . '<br/>';
        $body .= (isset($lang['order_phone']) ? $lang['order_phone'] : '') . '：' . (isset($order['phone']) ? $order['phone'] : '') . '<br/>';
        $body .= (isset($lang['order_address']) ? $lang['order_address'] : '') . '：' . (isset($order['address']) ? $order['address'] : '');

        $altBody = isset($lang['mail_altbody']) ? $lang['mail_altbody'] : '';

        $sent = self::deliver($siteEmail, $title, $body, $altBody);
        if ($sent) {
            Event::fire(SystemEvents::ORDER_PAYMENT_SITE_MAIL_SENT, array('order' => $order));
        }

        return (bool) $sent;
    }

    /**
     * 在线客服「待接入提醒」邮件，发送到 `param.chat_email`。
     *
     * `send_email_time` 的更新由调用方 `ChatService` 自行处理，本方法仅负责发信。
     *
     * @param Connection $db 数据库连接（保留以与其他方法签名一致，本方法内部不使用）。
     * @param array $lang 当前语言包。
     * @param mixed $serviceId 客服会话 ID。
     * @param string $chatUrl 客服会话 URL。
     * @return bool 是否成功送达。
     */
    public static function sendChatStaffNotification(Connection $db, array $lang, $serviceId, $chatUrl)
    {
        unset($db);

        $to = Config::get('param.chat_email');
        if (empty($to)) {
            return false;
        }

        $subject = isset($lang['chat_email_title']) ? $lang['chat_email_title'] : '';

        $body = (isset($lang['chat_email_custom_number']) ? $lang['chat_email_custom_number'] : '') . $serviceId . '<br>';
        $body .= (isset($lang['chat_email_body']) ? $lang['chat_email_body'] : '') . '<br>';
        $body .= '<a href="' . $chatUrl . '">' . $chatUrl . '</a>';

        $altBody = isset($lang['mail_altbody']) ? $lang['mail_altbody'] : '';

        return (bool) self::deliver($to, $subject, $body, $altBody);
    }

    /**
     * 前台会员忘记密码：发送找回密码邮件。
     *
     * @param string $email 收件人地址（即用户填写的邮箱）。
     * @param array $lang 当前语言包。
     * @param array $user 用户行（至少包含 `email`，用于正文展示）。
     * @param string $resetUrl 拼好的重置链接（完整 URL）。
     * @return bool 是否成功送达。
     */
    public static function sendUserPasswordResetMail($email, array $lang, array $user, $resetUrl)
    {
        if ($email === '' || $email === null) {
            return false;
        }

        $siteUrl = rtrim(ROOT_URL, '/');
        $userEmail = isset($user['email']) ? $user['email'] : '';

        $body = $userEmail . (isset($lang['user_password_reset_body_0']) ? $lang['user_password_reset_body_0'] : '');
        $body .= $resetUrl . (isset($lang['user_password_reset_body_1']) ? $lang['user_password_reset_body_1'] : '');
        $body .= Config::get('site.site_name', '') . '. ' . $siteUrl;

        $subject = isset($lang['user_password_reset_title']) ? $lang['user_password_reset_title'] : '';
        $altBody = isset($lang['mail_altbody']) ? $lang['mail_altbody'] : '';

        return (bool) self::deliver($email, $subject, $body, $altBody);
    }

    /**
     * 站点订单邮件发送成功后的扩展抄送（事件监听）。
     *
     * 当 `param.mail_order_payment_cc` 为有效邮箱时，向其抄送一封订单摘要。
     *
     * @param array $payload 来自 `ORDER_PAYMENT_SITE_MAIL_SENT` 的 payload，需含 `order` 数组。
     * @return void
     */
    public static function onOrderPaymentSiteMailSent($payload)
    {
        $cc = trim(Config::get('param.mail_order_payment_cc', ''));
        if ($cc === '' || !filter_var($cc, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        if (!is_array($payload)) {
            return;
        }

        $order = isset($payload['order']) && is_array($payload['order']) ? $payload['order'] : array();
        $sn = isset($order['order_sn']) ? $order['order_sn'] : '';

        $subject = '订单支付通知（抄送）：' . $sn;
        $body = '订单号：' . htmlspecialchars($sn, ENT_QUOTES, 'UTF-8') . '<br/>';
        if (isset($order['order_amount'])) {
            $body .= '金额：' . htmlspecialchars($order['order_amount'], ENT_QUOTES, 'UTF-8') . '<br/>';
        }
        if (isset($order['created_at'])) {
            $body .= '下单时间：' . (is_numeric($order['created_at']) ? date('Y-m-d H:i:s', (int) $order['created_at']) : $order['created_at']) . '<br/>';
        }

        $altBody = strip_tags(str_replace('<br/>', "\n", $body));

        self::deliver($cc, $subject, $body, $altBody);
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
