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

use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员 AI 应用主表（aigc）
 */
class Aigc extends Model
{
    protected $table = 'aigc';

    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'is_free' => 'int',
        'free_limit_value' => 'int',
        'is_public' => 'int',
        'sort' => 'int',
        'status' => 'int',
    );

    /** @var array */
    protected $fillable = array(
        'name',
        'slug',
        'type',
        'description',
        'icon',
        'cover_image',
        'model_ids',
        'default_prompt',
        'config',
        'is_free',
        'free_limit_type',
        'free_limit_value',
        'is_public',
        'sort',
        'status',
    );

    /**
     * 按能力类型筛选；空透传。
     *
     * @param Builder $query
     * @param mixed $type
     * @return Builder
     */
    public function scopeFilterByType(Builder $query, $type)
    {
        $type = is_scalar($type) ? trim((string) $type) : '';
        if ($type === '') {
            return $query;
        }

        return $query->where('type', $type);
    }

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
     * 默认列表排序：排序值升序，同值按主键升序。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('sort ASC, id ASC');
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    protected function setModelIdsAttribute($value)
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            $value = trim((string) $value);

            return $value === '' ? null : $value;
        }
        $filtered = array_filter(array_map('intval', $value));

        return empty($filtered) ? null : json_encode(array_values($filtered));
    }

    /**
     * @param mixed $value
     * @return string
     */
    protected function setConfigAttribute($value)
    {
        $value = trim((string) $value);

        return $value === '' ? '{}' : $value;
    }
}
