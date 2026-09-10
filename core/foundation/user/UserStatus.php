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

namespace Dou\Core\Foundation\User;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员状态枚举与表驱动迁移校验。
 *
 * 字符串状态枚举；合法迁移由 canTransit 表驱动（模式同 {@see \Dou\Core\Foundation\Order\OrderStatus}）。
 * 对应 dou_user.status：历史整数 1 = 正常 → ACTIVE、0 = 停用 → SUSPENDED。
 *
 * 状态语义：
 * - ACTIVE      正常（可登录、可下单）
 * - SUSPENDED   被管理员停用（可被恢复为 ACTIVE）
 * - DEACTIVATED 注销（终态，账户失效，不可恢复）
 *
 * 注：方案曾含 PENDING_VERIFICATION（待验证），因当前无邮箱/手机强验证落地业务而裁剪，
 * 需要时再补枚举位与对应迁移边。
 */
class UserStatus
{
    const ACTIVE = 'active';
    const SUSPENDED = 'suspended';
    const DEACTIVATED = 'deactivated';

    /**
     * 合法状态迁移表。键 = from，值 = 允许的 to 列表。
     *
     * @var array
     */
    private static $transitions = array(
        self::ACTIVE => array(self::SUSPENDED, self::DEACTIVATED),
        self::SUSPENDED => array(self::ACTIVE, self::DEACTIVATED),
        self::DEACTIVATED => array(),
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
            self::ACTIVE,
            self::SUSPENDED,
            self::DEACTIVATED,
        );
    }

    /**
     * 判断给定值是否为合法会员状态。
     *
     * @param mixed $status
     * @return bool
     */
    public static function isValid($status)
    {
        return in_array((string) $status, self::all(), true);
    }
}
