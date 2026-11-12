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

namespace Dou\Admin\Model\Aigc;

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员订阅记录表（aigc_subscription）
 */
class AigcSubscription extends Model
{
    protected $table = 'aigc_subscription';

    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'user_id' => 'int',
        'package_id' => 'int',
        'start_at' => 'datetime:Y-m-d H:i:s',
        'end_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
    );

    /**
     * 按会员筛选；0 透传。
     *
     * @param Builder $query
     * @param mixed $userId
     * @return Builder
     */
    public function scopeFilterByUserId(Builder $query, $userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return $query;
        }

        return $query->where('user_id', $userId);
    }

    /**
     * 按套餐筛选；0 透传。
     *
     * @param Builder $query
     * @param mixed $packageId
     * @return Builder
     */
    public function scopeFilterByPackageId(Builder $query, $packageId)
    {
        $packageId = (int) $packageId;
        if ($packageId <= 0) {
            return $query;
        }

        return $query->where('package_id', $packageId);
    }

    /**
     * 按订单状态筛选（OrderStatus 字符串枚举）；空透传。
     *
     * @param Builder $query
     * @param mixed $orderStatusRaw
     * @return Builder
     */
    public function scopeFilterByOrderStatus(Builder $query, $orderStatusRaw)
    {
        $s = is_scalar($orderStatusRaw) ? trim((string) $orderStatusRaw) : '';
        if ($s === '') {
            return $query;
        }

        return $query->where('order_status', $s);
    }

    /**
     * 按订单号筛选；空透传。
     *
     * @param Builder $query
     * @param mixed $orderSn
     * @return Builder
     */
    public function scopeFilterByOrderSn(Builder $query, $orderSn)
    {
        $orderSn = is_scalar($orderSn) ? trim((string) $orderSn) : '';
        if ($orderSn === '') {
            return $query;
        }

        return $query->where('order_sn', $orderSn);
    }

    /**
     * 默认排序：最近购买优先。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('created_at DESC, id DESC');
    }

    /**
     * 跨表读套餐行（aigc_package）。
     *
     * @param int $packageId
     * @return array|null
     */
    public static function findPackageRow($packageId)
    {
        $packageId = (int) $packageId;
        if ($packageId <= 0) {
            return null;
        }

        return DB::table('aigc_package')->find($packageId);
    }
}
