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

namespace Dou\Core\Foundation\Vip;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员 VIP 生命周期状态（派生值，非数据库列）。
 *
 * dou_vip.order_status 存的是「购买订单」的状态快照（order_status_ 文案），
 * 真正的 VIP 会员有效期由 start_at / end_at 决定。本枚举把有效期判定收口为
 * 三态，供会员中心展示与续费提示使用。
 *
 * - ACTIVE   生效中：start_at <= now < end_at
 * - EXPIRED  已过期：end_at <= now
 * - NONE     无 VIP 记录
 */
class VipStatus
{
    const ACTIVE = 'active';
    const EXPIRED = 'expired';
    const NONE = 'none';

    /**
     * 按起止时间判定当前 VIP 状态。
     *
     * @param int $startTime 生效时间戳
     * @param int $endTime 到期时间戳
     * @param int|null $now 参照时间戳（默认当前时间）
     * @return string ACTIVE / EXPIRED
     */
    public static function classify($startTime, $endTime, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $startTime = (int) $startTime;
        $endTime = (int) $endTime;

        if ($endTime > $now && $startTime <= $now) {
            return self::ACTIVE;
        }
        if ($endTime > $now && $startTime > $now) {
            // 续费叠加产生的未来生效区间，仍按生效中展示。
            return self::ACTIVE;
        }

        return self::EXPIRED;
    }

    /**
     * 全部派生状态值列表。
     *
     * @return array
     */
    public static function all()
    {
        return array(
            self::ACTIVE,
            self::EXPIRED,
            self::NONE,
        );
    }

    /**
     * 状态徽章语义配色：生效中→success；已过期→danger；无记录→info。
     *
     * @param mixed $status
     * @return string success|danger|info
     */
    public static function badgeClass($status)
    {
        $status = (string) $status;
        if ($status === self::ACTIVE) {
            return 'success';
        }
        if ($status === self::EXPIRED) {
            return 'danger';
        }
        return 'info';
    }
}
