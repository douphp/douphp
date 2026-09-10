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

namespace Dou\Core\Foundation\Order;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 订单状态枚举与表驱动迁移校验。
 *
 * 主流框架风格的字符串状态枚举，配合 dou_order.status / dou_order_item.order_status (varchar)。
 *
 * 状态语义：
 * - PENDING               下单成功，等待付款
 * - AWAITING_CONFIRMATION 离线付款已上传凭证，等待管理员审核
 * - PAID                  已付款
 * - COMPLETED             已完成（无物流业务从 PAID 直接跨到 COMPLETED；
 *                         有物流业务在发货完成后到 COMPLETED）
 * - CANCELLED             已取消（含自动取消未付款超时订单）
 * - REFUNDING             退款中（售后审核通过后进入，等待退款落定）
 * - REFUNDED             已全额退款（终态）
 * - PARTIAL_REFUNDED      已部分退款（仍可继续退至全额 → REFUNDING）
 */
class OrderStatus
{
    const PENDING = 'pending';
    const AWAITING_CONFIRMATION = 'awaiting_confirmation';
    const PAID = 'paid';
    const COMPLETED = 'completed';
    const CANCELLED = 'cancelled';
    const REFUNDING = 'refunding';
    const REFUNDED = 'refunded';
    const PARTIAL_REFUNDED = 'partial_refunded';

    /**
     * 合法状态迁移表。
     *
     * 键 = from，值 = 允许的 to 列表。
     * 离线付款审核驳回时 AWAITING_CONFIRMATION → PENDING，允许用户重新发起支付。
     * 退款链：PAID / COMPLETED 在售后周期内可进 REFUNDING；REFUNDING 驳回回退 PAID，
     * 退款落定到 REFUNDED（全额，终态）或 PARTIAL_REFUNDED（部分，可继续退）。
     *
     * @var array
     */
    private static $transitions = array(
        self::PENDING => array(self::AWAITING_CONFIRMATION, self::PAID, self::CANCELLED),
        self::AWAITING_CONFIRMATION => array(self::PAID, self::PENDING, self::CANCELLED),
        self::PAID => array(self::COMPLETED, self::REFUNDING),
        self::COMPLETED => array(self::REFUNDING),
        self::REFUNDING => array(self::REFUNDED, self::PARTIAL_REFUNDED, self::PAID),
        self::PARTIAL_REFUNDED => array(self::REFUNDING),
        self::REFUNDED => array(),
        self::CANCELLED => array(),
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
            self::AWAITING_CONFIRMATION,
            self::PAID,
            self::COMPLETED,
            self::CANCELLED,
            self::REFUNDING,
            self::REFUNDED,
            self::PARTIAL_REFUNDED,
        );
    }

    /**
     * 判断给定值是否为合法订单状态。
     *
     * @param mixed $status
     * @return bool
     */
    public static function isValid($status)
    {
        return in_array((string) $status, self::all(), true);
    }

    /**
     * 状态徽章语义配色：终态成功→success；待处理/进行中→warning；取消→danger；信息态→info。
     *
     * @param mixed $status
     * @return string success|warning|danger|info
     */
    public static function badgeClass($status)
    {
        $status = (string) $status;
        if ($status === self::COMPLETED) {
            return 'success';
        }
        if ($status === self::CANCELLED) {
            return 'danger';
        }
        if ($status === self::PAID || $status === self::REFUNDED || $status === self::PARTIAL_REFUNDED) {
            return 'info';
        }
        return 'warning';
    }
}
