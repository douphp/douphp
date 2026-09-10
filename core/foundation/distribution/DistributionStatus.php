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

namespace Dou\Core\Foundation\Distribution;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 分销申请状态枚举与表驱动迁移校验。
 *
 * 字符串状态枚举；合法迁移由 canTransit 表驱动（模式同 {@see \Dou\Core\Foundation\Order\OrderStatus}）。
 * 对应 dou_distribution.status（varchar）。
 *
 * - PENDING   待审核
 * - APPROVED  已通过（成为分销员，写入 distribution_level_id）
 * - REJECTED  已驳回（终态）
 */
class DistributionStatus
{
    const PENDING = 'pending';
    const APPROVED = 'approved';
    const REJECTED = 'rejected';

    /**
     * 合法状态迁移表。键 = from，值 = 允许的 to 列表。
     *
     * @var array
     */
    private static $transitions = array(
        self::PENDING => array(self::APPROVED, self::REJECTED),
        self::APPROVED => array(),
        self::REJECTED => array(),
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
        );
    }

    /**
     * 判断给定值是否为合法分销申请状态。
     *
     * @param mixed $status
     * @return bool
     */
    public static function isValid($status)
    {
        return in_array((string) $status, self::all(), true);
    }

    /**
     * 状态对应的展示样式 class。
     *
     * @param mixed $status
     * @return string success/warning/danger
     */
    public static function badgeClass($status)
    {
        $status = (string) $status;
        if ($status === self::APPROVED) {
            return 'success';
        }
        if ($status === self::REJECTED) {
            return 'danger';
        }
        return 'warning';
    }
}
