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

namespace Dou\Core\Foundation\Aftersale;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 售后状态枚举与表驱动状态迁移校验（参见 {@see \Dou\Core\Foundation\Order\OrderStatus}）。
 *
 * 配合 dou_aftersale.status (varchar)。买家 / 卖家协商 + 寄回 + 退款的完整生命周期。
 *
 * 状态语义：
 * - PENDING        申请中（待卖家审核）
 * - APPROVED       卖家同意
 * - REJECTED       卖家驳回（终态）
 * - CANCELLED      买家撤销 / 逾期未寄回系统自动撤销（终态）
 * - WAITING_RETURN 等待买家寄回（仅 refund_with_return）
 * - RETURN_SHIPPED 买家已寄出（填运单）
 * - RECEIVED       卖家确认收货
 * - REFUNDING      退款中（refund_only 审核通过直接进，或收货后进）
 * - REFUNDED       退款成功（终态）
 * - REFUND_FAILED  退款失败（网关无回调等，待人工介入，可重试回 REFUNDING）
 */
class AftersaleStatus
{
    const PENDING = 'pending';
    const APPROVED = 'approved';
    const REJECTED = 'rejected';
    const CANCELLED = 'cancelled';
    const WAITING_RETURN = 'waiting_return';
    const RETURN_SHIPPED = 'return_shipped';
    const RECEIVED = 'received';
    const REFUNDING = 'refunding';
    const REFUNDED = 'refunded';
    const REFUND_FAILED = 'refund_failed';

    /**
     * 合法状态迁移表。键 = from，值 = 允许的 to 列表。
     *
     * @var array
     */
    private static $transitions = array(
        self::PENDING => array(self::APPROVED, self::REJECTED, self::CANCELLED),
        self::APPROVED => array(self::WAITING_RETURN, self::REFUNDING),
        self::WAITING_RETURN => array(self::RETURN_SHIPPED, self::CANCELLED),
        self::RETURN_SHIPPED => array(self::RECEIVED, self::REJECTED),
        self::RECEIVED => array(self::REFUNDING, self::REJECTED),
        self::REFUNDING => array(self::REFUNDED, self::REFUND_FAILED),
        self::REFUND_FAILED => array(self::REFUNDING),
        self::REFUNDED => array(),
        self::REJECTED => array(),
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
            self::APPROVED,
            self::REJECTED,
            self::CANCELLED,
            self::WAITING_RETURN,
            self::RETURN_SHIPPED,
            self::RECEIVED,
            self::REFUNDING,
            self::REFUNDED,
            self::REFUND_FAILED,
        );
    }

    /**
     * 终态集合（不可再迁出）。
     *
     * @return array
     */
    public static function terminal()
    {
        return array(self::REFUNDED, self::REJECTED, self::CANCELLED);
    }

    /**
     * 判断给定值是否为合法售后状态。
     *
     * @param mixed $status
     * @return bool
     */
    public static function isValid($status)
    {
        return in_array((string) $status, self::all(), true);
    }
}
