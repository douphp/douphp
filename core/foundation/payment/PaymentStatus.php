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

namespace Dou\Core\Foundation\Payment;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 单笔支付尝试的状态枚举与表驱动迁移校验。
 *
 * 一个订单可能有多次支付尝试（用户付款失败 / 切换网关重试 / 离线付款多次提交），
 * 因此 payment 状态独立于 order 状态。
 *
 * 状态语义：
 * - PENDING   已发起，等待结果
 *             - 在线支付：用户还在第三方支付页或回调未到达
 *             - 离线付款：用户已上传凭证或尚未上传，待管理员审核
 * - SUCCEEDED 成功（第三方异步回调验签通过 / 后台审核通过 / 主动对账确认成功）
 * - FAILED    失败（第三方明确返回失败 / 后台审核驳回 / 验签失败）
 * - CLOSED    关闭（超时未付款 / 第三方明确说已关闭）
 * - REFUNDED         已全额退款（成功的支付被全额退回，终态）
 * - PARTIAL_REFUNDED 已部分退款（成功的支付被部分退回，可继续退至全额 → REFUNDED）
 */
class PaymentStatus
{
    const PENDING = 'pending';
    const SUCCEEDED = 'succeeded';
    const FAILED = 'failed';
    const CLOSED = 'closed';
    const REFUNDED = 'refunded';
    const PARTIAL_REFUNDED = 'partial_refunded';

    /**
     * 合法状态迁移表。
     *
     * 成功支付被退款时：全额退 → REFUNDED；部分退 → PARTIAL_REFUNDED，可累退至 REFUNDED。
     *
     * @var array
     */
    private static $transitions = array(
        self::PENDING => array(self::SUCCEEDED, self::FAILED, self::CLOSED),
        self::SUCCEEDED => array(self::REFUNDED, self::PARTIAL_REFUNDED),
        self::PARTIAL_REFUNDED => array(self::REFUNDED),
        self::FAILED => array(),
        self::CLOSED => array(),
        self::REFUNDED => array(),
    );

    /**
     * 校验状态迁移是否合法。
     *
     * @param string $from
     * @param string $to
     * @return bool
     */
    public static function canTransit($from, $to)
    {
        $from = (string) $from;
        $to = (string) $to;
        if (!isset(self::$transitions[$from])) {
            return false;
        }
        return in_array($to, self::$transitions[$from], true);
    }

    /**
     * 全部状态值列表。
     *
     * @return array
     */
    public static function all()
    {
        return array(
            self::PENDING,
            self::SUCCEEDED,
            self::FAILED,
            self::CLOSED,
            self::REFUNDED,
            self::PARTIAL_REFUNDED,
        );
    }

    /**
     * 判断给定值是否为合法 payment 状态。
     *
     * @param mixed $status
     * @return bool
     */
    public static function isValid($status)
    {
        return in_array((string) $status, self::all(), true);
    }
}
