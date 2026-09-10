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

namespace Dou\Core\Foundation\Withdraw;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 提现状态枚举与表驱动迁移校验。
 *
 * 字符串状态枚举；合法迁移由 canTransit 表驱动（模式同 {@see \Dou\Core\Foundation\Order\OrderStatus}）。
 * 对应 dou_withdraw.status（varchar）。申请成立即从余额扣款，故各状态资金语义如下：
 *
 * - PENDING   待审核：余额已在申请时扣除，等待管理员处理
 * - APPROVED  已通过：管理员核准，款项将线下打款（余额维持扣除）
 * - PAID      已打款：线下转账完成（终态）
 * - REJECTED  已驳回：管理员驳回，须把申请时扣除的余额原路退回（终态）
 */
class WithdrawStatus
{
    const PENDING = 'pending';
    const APPROVED = 'approved';
    const REJECTED = 'rejected';
    const PAID = 'paid';

    /**
     * 合法状态迁移表。键 = from，值 = 允许的 to 列表。
     *
     * 驳回只允许从 PENDING 发生（核准后不再回退，避免已打款后误退）。
     *
     * @var array
     */
    private static $transitions = array(
        self::PENDING => array(self::APPROVED, self::REJECTED),
        self::APPROVED => array(self::PAID),
        self::PAID => array(),
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
            self::PAID,
        );
    }

    /**
     * 判断给定值是否为合法提现状态。
     *
     * @param mixed $status
     * @return bool
     */
    public static function isValid($status)
    {
        return in_array((string) $status, self::all(), true);
    }

    /**
     * 状态对应的展示样式 class（与既有徽章语义对齐）。
     *
     * @param mixed $status
     * @return string success/warning/danger/info
     */
    public static function badgeClass($status)
    {
        $status = (string) $status;
        if ($status === self::PAID) {
            return 'success';
        }
        if ($status === self::REJECTED) {
            return 'danger';
        }
        if ($status === self::APPROVED) {
            return 'info';
        }
        return 'warning';
    }
}
