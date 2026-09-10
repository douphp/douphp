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

namespace Dou\Vendor\Mail;

use Dou\Core\Foundation\Configuration\Config;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * PHPMailer 加载与全站统一发信入口。
 */
class Mail {

    /**
     * @return \PHPMailer
     */
    public function createMailer() {
        require_once LIBRARY_PATH . 'mail/src/PhpMailer.php';
        require_once LIBRARY_PATH . 'mail/src/Smtp.php';
        return new \PHPMailer();
    }

    /**
     * 发送邮件（SMTP 或 PHP mail，由站点配置决定）。
     *
     * @param mixed  $mailto  收件人地址。
     * @param string $subject 邮件主题。
     * @param string $body    邮件正文（HTML）。
     * @param string $altBody 非 HTML 客户端显示的纯文本备用正文。
     * @return string|null 成功返回 success，失败或未发送时返回 null。
     */
    public static function sendMail($mailto, $subject = '', $body = '', $altBody = '') {
        $result = self::sendMailResult($mailto, $subject, $body, $altBody);
        return !empty($result['success']) ? 'success' : null;
    }

    /**
     * 发送邮件并返回结构化结果（成功与否 + 失败时的错误信息）。
     *
     * 供需要向用户反馈发送结果（如后台「测试邮件」）的调用方使用；
     * 语义与 {@see sendMail} 完全一致，仅返回值更丰富。
     *
     * @param mixed  $mailto  收件人地址。
     * @param string $subject 邮件主题。
     * @param string $body    邮件正文（HTML）。
     * @param string $altBody 非 HTML 客户端显示的纯文本备用正文。
     * @param array|null $debugTraces 引用变量：非 null 时收集 SMTP 调试链路（调试级别 2），
     *                                键为整数序号、值为 "level=N 消息" 字符串。
     * @return array{success: bool, error: string} success 表示是否发送成功；error 为失败时的错误信息（成功时为空串）。
     */
    public static function sendMailResult($mailto, $subject = '', $body = '', $altBody = '', &$debugTraces = null) {
        if (Config::get('site.mail_service', false)) {
            $mailFactory = new self();
            $mail = $mailFactory->createMailer();

            if (!$mail) {
                return array('success' => false, 'error' => 'Mailer init failed');
            }

            // 若调用方传入 debugTraces 引用，开启 SMTP 调试采集
            $collectDebug = ($debugTraces !== null);
            if ($collectDebug) {
                $debugTraces = array();
                $mail->SMTPDebug = 2;
                $mail->Debugoutput = function ($str, $level) use (&$debugTraces) {
                    $debugTraces[] = '[level=' . $level . '] ' . rtrim($str);
                };
            }

            $mail->CharSet = "UTF-8";                              // 设定邮件编码
            $mail->isSMTP();                                      // 设定使用SMTP服务
            $mail->Host = Config::get('site.mail_host', '');          // SMTP服务器
            $mail->SMTPAuth = true;                               // 启用SMTP验证功能
            $mail->Username = Config::get('site.mail_username', '');  // SMTP服务器用户名
            $mail->Password = Config::get('site.mail_password', '');  // SMTP服务器密码
            if (Config::get('site.mail_ssl', false)) {
                $mail->SMTPSecure = 'ssl';                        // 安全协议，可以注释掉
            }
            $mail->Port = Config::get('site.mail_port', 25);          // SMTP服务器的端口号

            $mail->From = Config::get('site.mail_username', '');      // 发件人地址
            $mail->FromName = Config::get('site.site_name', '');      // 发件人姓名
            $mail->addAddress($mailto, '');                       // 收件地址，可选指定收件人姓名

            $mail->isHTML(true);                                  // 是否HTML格式邮件

            $mail->Subject = $subject;                            // 邮件标题
            $mail->Body = $body;                               // 邮件内容

            // 邮件正文不支持HTML的备用显示
            $mail->AltBody = $altBody;

            if ($mail->send()) {
                return array('success' => true, 'error' => '');
            }
            return array('success' => false, 'error' => (string) $mail->ErrorInfo);
        }

        // 主题 / 发件人名称按 RFC 2047 编码，避免中文等非 ASCII 文本在邮件头里乱码
        $subject = self::encodeMimeHeader($subject);
        $fromName = self::encodeMimeHeader(Config::get('site.site_name', ''));
        $header = "From: " . $fromName . " <" . Config::get('site.mail_username', '') . ">\n";
        $header .= "Return-Path: <" . Config::get('site.mail_username', '') . ">\n";   // 防止被当做垃圾邮件
        $header .= "MIME-Version: 1.0\n";
        $header .= "Content-type: text/html; charset=utf-8\n";         // 邮件内容为utf-8编码
        $header .= "Content-Transfer-Encoding: 8bit\r\n";              // 注意header的结尾，只有这个后面有\r
        ini_set('sendmail_from', Config::get('site.mail_username', ''));           // 解决mail的一个bug
        $body = wordwrap($body, 70);                                   // 每行最多70个字符,这个是mail方法的限制
        if (mail($mailto, $subject, $body, $header)) {
            return array('success' => true, 'error' => '');
        }
        $lastError = error_get_last();
        return array('success' => false, 'error' => $lastError ? (string) $lastError['message'] : '');
    }

    /**
     * RFC 2047 编码邮件头字段（主题 / 发件人显示名）。
     *
     * 邮件头只允许 ASCII，中文等非 ASCII 文本必须编码为 encoded-word，否则收件客户端会显示乱码。
     * 优先用 mbstring（自动按行宽折叠长文本），不可用时回退到 base64 编码字。
     *
     * @param string $value 原始文本（UTF-8）。
     * @return string 纯 ASCII 时原样返回；否则返回 encoded-word。
     */
    private static function encodeMimeHeader($value)
    {
        $value = (string) $value;
        if ($value === '' || !preg_match('/[\x80-\xFF]/', $value)) {
            return $value;
        }

        if (function_exists('mb_encode_mimeheader')) {
            $prevEncoding = mb_internal_encoding();
            mb_internal_encoding('UTF-8');
            $encoded = mb_encode_mimeheader($value, 'UTF-8', 'B', "\n");
            mb_internal_encoding($prevEncoding);
            return $encoded;
        }

        // 兜底：base64 编码字（短文本足够；无 mbstring 时不再按行折叠）
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
