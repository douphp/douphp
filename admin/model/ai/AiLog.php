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

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * AI 用量日志表 ai_log
 */
class AiLog extends Model
{
    protected $table = 'ai_log';

    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'admin_id' => 'int',
        'provider_id' => 'int',
        'model_id' => 'int',
        'has_error' => 'int',
    );

    /** @var array */
    protected $fillable = array(
        'admin_id',
        'app_id',
        'model_id',
        'provider_id',
        'key_id',
        'request_id',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'duration',
        'status_code',
        'has_error',
        'error_message',
        'prompt_content',
        'response_content',
        'endpoint',
        'ip',
        'metadata',
        'created_at',
    );

    /**
     * 按 admin_id 筛选；<=0 透传。
     *
     * @param Builder $query
     * @param mixed $adminId
     * @return Builder
     */
    public function scopeFilterByAdminId(Builder $query, $adminId)
    {
        $adminId = (int) $adminId;
        if ($adminId <= 0) {
            return $query;
        }

        return $query->where('admin_id', $adminId);
    }

    /**
     * 按 provider_id 筛选；<=0 透传。
     *
     * @param Builder $query
     * @param mixed $providerId
     * @return Builder
     */
    public function scopeFilterByProviderId(Builder $query, $providerId)
    {
        $providerId = (int) $providerId;
        if ($providerId <= 0) {
            return $query;
        }

        return $query->where('provider_id', $providerId);
    }

    /**
     * 按 model_id 筛选；<=0 透传。
     *
     * @param Builder $query
     * @param mixed $modelId
     * @return Builder
     */
    public function scopeFilterByModelId(Builder $query, $modelId)
    {
        $modelId = (int) $modelId;
        if ($modelId <= 0) {
            return $query;
        }

        return $query->where('model_id', $modelId);
    }

    /**
     * 按 has_error 筛选；非数字字符串透传。
     *
     * @param Builder $query
     * @param mixed $hasErrorRaw
     * @return Builder
     */
    public function scopeFilterByHasError(Builder $query, $hasErrorRaw)
    {
        $s = is_scalar($hasErrorRaw) ? trim((string) $hasErrorRaw) : '';
        if ($s === '') {
            return $query;
        }

        return $query->where('has_error', (int) $s);
    }

    /**
     * 按 created_at 起始时间筛选；空字符串透传。
     *
     * @param Builder $query
     * @param mixed $timeStart
     * @return Builder
     */
    public function scopeFilterByCreatedAtStart(Builder $query, $timeStart)
    {
        $timeStart = is_scalar($timeStart) ? trim((string) $timeStart) : '';
        if ($timeStart === '') {
            return $query;
        }

        return $query->where('created_at', '>=', $timeStart);
    }

    /**
     * 按 created_at 结束时间筛选；空字符串透传。
     *
     * @param Builder $query
     * @param mixed $timeEnd
     * @return Builder
     */
    public function scopeFilterByCreatedAtEnd(Builder $query, $timeEnd)
    {
        $timeEnd = is_scalar($timeEnd) ? trim((string) $timeEnd) : '';
        if ($timeEnd === '') {
            return $query;
        }

        return $query->where('created_at', '<=', $timeEnd);
    }

    /**
     * 默认列表排序（id DESC）。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('id DESC');
    }

    /**
     * @param int $id
     * @return array|null
     */
    public static function findAdminRow($id)
    {
        return static::where('id', (int) $id)->first();
    }

    /**
     * @param int $providerId
     * @return string|null
     */
    public static function getProviderName($providerId)
    {
        if (!$providerId) {
            return null;
        }

        return DB::table('ai_provider')->where('id', (int) $providerId)->value('name');
    }

    /**
     * @param int $modelId
     * @return string|null
     */
    public static function getModelName($modelId)
    {
        if (!$modelId) {
            return null;
        }

        return DB::table('ai_model')->where('id', (int) $modelId)->value('name');
    }

    /**
     * @param int $appId
     * @return string|null
     */
    public static function getApplicationName($appId)
    {
        if (!$appId) {
            return null;
        }

        return DB::table('ai')->where('id', (int) $appId)->value('name');
    }

    /**
     * @param int $adminId
     * @return string|null
     */
    public static function getAdminName($adminId)
    {
        if (!$adminId) {
            return null;
        }

        return DB::table('admin')->where('id', (int) $adminId)->value('username');
    }
}
