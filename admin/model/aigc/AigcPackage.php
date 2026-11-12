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
 * AI 套餐表（aigc_package）
 */
class AigcPackage extends Model
{
    protected $table = 'aigc_package';

    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'day' => 'int',
        'promote_start_at' => 'datetime:Y-m-d H:i:s',
        'promote_end_at' => 'datetime:Y-m-d H:i:s',
        'sort' => 'int',
        'status' => 'int',
        'image' => 'attachment',
    );

    /** @var array */
    protected $fillable = array(
        'name',
        'day',
        'tokens_limit',
        'requests_limit',
        'price',
        'level_price',
        'promote_price',
        'promote_start_at',
        'promote_end_at',
        'content',
        'image',
        'sort',
        'status',
    );

    /**
     * 按状态筛选；非数字字符串视为不限。
     *
     * @param Builder $query
     * @param mixed $statusRaw
     * @return Builder
     */
    public function scopeFilterByStatus(Builder $query, $statusRaw)
    {
        $s = is_scalar($statusRaw) ? trim((string) $statusRaw) : '';
        if ($s === '') {
            return $query;
        }

        return $query->where('status', (int) $s);
    }

    /**
     * 默认列表排序。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('sort ASC, id DESC');
    }

    /**
     * 套餐是否已有订阅记录（删除前守卫）。
     *
     * @param int $packageId
     * @return bool
     */
    public static function subscriptionExistsForPackage($packageId)
    {
        return DB::table('aigc_subscription')->where('package_id', (int) $packageId)->exists();
    }

    /**
     * 启用中的套餐（id / name），筛选下拉用。
     *
     * @return array
     */
    public static function selectActivePackages()
    {
        return (array) DB::table('aigc_package')
            ->field('id, name')
            ->where('status', 1)
            ->order('sort ASC, id ASC')
            ->select();
    }
}
