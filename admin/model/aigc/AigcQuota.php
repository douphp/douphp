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
 * 会员配额表（aigc_quota）
 */
class AigcQuota extends Model
{
    protected $table = 'aigc_quota';

    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'user_id' => 'int',
    );

    /** @var array */
    protected $fillable = array(
        'user_id',
        'quota_type',
        'total_limit',
        'used_amount',
        'reset_cycle',
        'expires_at',
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
     * 按配额类型筛选；空透传。
     *
     * @param Builder $query
     * @param mixed $quotaType
     * @return Builder
     */
    public function scopeFilterByQuotaType(Builder $query, $quotaType)
    {
        $quotaType = is_scalar($quotaType) ? trim((string) $quotaType) : '';
        if ($quotaType === '') {
            return $query;
        }

        return $query->where('quota_type', $quotaType);
    }

    /**
     * 默认排序。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('user_id ASC, quota_type ASC');
    }

    /**
     * 同会员同配额类型的记录数（排除指定 id，唯一性守卫）。
     *
     * @param int $userId
     * @param string $quotaType
     * @param int $excludeId
     * @return int
     */
    public static function countByUserAndQuotaType($userId, $quotaType, $excludeId = 0)
    {
        $query = DB::table('aigc_quota')
            ->where('user_id', (int) $userId)
            ->where('quota_type', (string) $quotaType);
        if ((int) $excludeId > 0) {
            $query->where('id', '<>', (int) $excludeId);
        }

        return (int) $query->count();
    }

    /**
     * 批量清零已用量。
     *
     * @param array $ids
     * @return void
     */
    public static function resetUsedAmountForIds(array $ids)
    {
        $intIds = array();
        foreach ($ids as $id) {
            if ((int) $id > 0) {
                $intIds[] = (int) $id;
            }
        }
        if (!$intIds) {
            return;
        }

        DB::table('aigc_quota')
            ->where('id', 'IN', $intIds)
            ->data(array(
                'used_amount' => 0,
                'last_reset_at' => date('Y-m-d H:i:s'),
            ))->update();
    }
}
