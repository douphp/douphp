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
 * 售后类型枚举。
 *
 * - REFUND_ONLY        仅退款（未发货 / 数字商品 / 已收货无理由）
 * - REFUND_WITH_RETURN 退货退款（已发货 + 需买家寄回）
 * - EXCHANGE           换货（预留枚举位）
 * - REPAIR             维修（预留枚举位）
 */
class AftersaleType
{
    const REFUND_ONLY = 'refund_only';
    const REFUND_WITH_RETURN = 'refund_with_return';
    const EXCHANGE = 'exchange';
    const REPAIR = 'repair';

    /**
     * 全部类型值列表。
     *
     * @return array
     */
    public static function all()
    {
        return array(
            self::REFUND_ONLY,
            self::REFUND_WITH_RETURN,
            self::EXCHANGE,
            self::REPAIR,
        );
    }

    /**
     * 是否需要买家寄回。
     *
     * @param string $type
     * @return bool
     */
    public static function requiresReturn($type)
    {
        return (string) $type === self::REFUND_WITH_RETURN;
    }

    /**
     * 判断给定值是否为合法售后类型。
     *
     * @param mixed $type
     * @return bool
     */
    public static function isValid($type)
    {
        return in_array((string) $type, self::all(), true);
    }
}
