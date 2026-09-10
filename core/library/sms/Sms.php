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

namespace Dou\Vendor\Sms;

use Dou\Vendor\Sms\Src\Alisms;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 短信服务统一入口（门面）。
 *
 * 默认走阿里云通道（{@see Alisms}）。业务侧由此入口创建实例，
 * 内部所需配置通过 helper / Config 即用即取。
 */
class Sms
{
    /**
     * 创建短信客户端实例。
     *
     * @param string $client          客户端标识（web/miniprogram/api）。
     * @param string $storageSmsToken miniprogram/api 模式下用于校验的 token。
     * @param string $accessKeyId     可选覆盖：阿里云 AccessKeyId。
     * @param string $accessKeySecret 可选覆盖：阿里云 AccessKeySecret。
     * @param string $signName        可选覆盖：短信签名。
     * @param bool   $security        是否启用 HTTPS。
     * @return Alisms
     */
    public function createClient($client = 'web', $storageSmsToken = '', $accessKeyId = '', $accessKeySecret = '', $signName = '', $security = false)
    {
        return new Alisms($client, $storageSmsToken, $accessKeyId, $accessKeySecret, $signName, $security);
    }

    /**
     * 一站式发送短信。
     *
     * @param mixed $phoneNumber   接收手机号。
     * @param mixed $templateCode  模板 CODE。
     * @param mixed $templateParam 模板参数（数组）。
     * @return mixed `success` 表示成功，其它为错误信息字符串/原始返回码。
     */
    public static function sendSms($phoneNumber, $templateCode, $templateParam)
    {
        $factory = new self();
        $client = $factory->createClient();
        return $client->sendSms($phoneNumber, $templateCode, $templateParam);
    }
}
