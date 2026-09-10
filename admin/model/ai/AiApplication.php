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

namespace Dou\Admin\Model\Ai;

use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * AI 应用表（ai）
 */
class AiApplication extends Model
{
    protected $table = 'ai';

    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'model_id' => 'int',
        'sort' => 'int',
        'status' => 'int',
    );

    /**
     * 允许批量写入字段（与 ApplicationFormRequest::rules 分工：fillable 管入库）。
     *
     * @var array
     */
    protected $fillable = array(
        'name',
        'placement',
        'task_type',
        'module',
        'field',
        'model_id',
        'default_prompt',
        'config',
        'sort',
        'status',
    );

    /**
     * 按应用形态筛选（assist/fill/batch/translate）；空字符串透传。
     *
     * @param Builder $query
     * @param mixed $placement
     * @return Builder
     */
    public function scopeFilterByPlacement(Builder $query, $placement)
    {
        $placement = is_scalar($placement) ? trim((string) $placement) : '';
        if ($placement === '') {
            return $query;
        }

        return $query->where('placement', $placement);
    }

    /**
     * 按任务形态筛选；空字符串透传。
     *
     * @param Builder $query
     * @param mixed $taskType
     * @return Builder
     */
    public function scopeFilterByTaskType(Builder $query, $taskType)
    {
        $taskType = is_scalar($taskType) ? trim((string) $taskType) : '';
        if ($taskType === '') {
            return $query;
        }

        return $query->where('task_type', $taskType);
    }

    /**
     * 按挂载模块筛选；空字符串透传。
     *
     * @param Builder $query
     * @param mixed $module
     * @return Builder
     */
    public function scopeFilterByModule(Builder $query, $module)
    {
        $module = is_scalar($module) ? trim((string) $module) : '';
        if ($module === '') {
            return $query;
        }

        return $query->where('module', $module);
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
     * 默认列表排序（id DESC）。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('sort ASC, id DESC');
    }

    /**
     * @param mixed $value
     * @return string
     */
    protected function setTaskTypeAttribute($value)
    {
        $value = trim((string) $value);

        return $value === '' ? 'assist' : $value;
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    protected function setModuleAttribute($value)
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    protected function setFieldAttribute($value)
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            return null;
        }
        $vals = array();
        foreach ($value as $v) {
            if ($v !== '' && $v !== null) {
                $vals[] = $v;
            }
        }
        $vals = array_values($vals);

        return empty($vals) ? null : json_encode($vals);
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
