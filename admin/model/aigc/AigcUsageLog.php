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
 * 会员侧 AI 调用日志表（aigc_usage_log）
 */
class AigcUsageLog extends Model
{
    protected $table = 'aigc_usage_log';

    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'user_id' => 'int',
        'app_id' => 'int',
        'session_id' => 'int',
        'model_id' => 'int',
        'provider_id' => 'int',
        'key_id' => 'int',
        'prompt_tokens' => 'int',
        'completion_tokens' => 'int',
        'total_tokens' => 'int',
        'duration' => 'int',
        'status' => 'int',
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
     * 按应用筛选；0 透传。
     *
     * @param Builder $query
     * @param mixed $chatId
     * @return Builder
     */
    public function scopeFilterByAigcId(Builder $query, $chatId)
    {
        $chatId = (int) $chatId;
        if ($chatId <= 0) {
            return $query;
        }

        return $query->where('app_id', $chatId);
    }

    /**
     * 按调用状态筛选；非数字字符串视为不限。
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
     * 按供应商筛选；0 透传。
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
     * 按模型筛选；0 透传。
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
     * 按是否错误筛选；非数字字符串视为不限。
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
     * 默认排序：最近调用优先。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('id DESC');
    }

    /**
     * 跨表读供应商名（ai_provider）。
     *
     * @param int $providerId
     * @return string
     */
    public static function getProviderName($providerId)
    {
        $name = DB::table('ai_provider')->where('id', (int) $providerId)->value('name');

        return $name !== null && $name !== '' ? $name : '-';
    }

    /**
     * 跨表读模型名（ai_model）。
     *
     * @param int $modelId
     * @return string
     */
    public static function getModelName($modelId)
    {
        $name = DB::table('ai_model')->where('id', (int) $modelId)->value('name');

        return $name !== null && $name !== '' ? $name : '-';
    }

    /**
     * 跨表读应用名（aigc）。
     *
     * @param int $chatId
     * @return string
     */
    public static function getAigcName($chatId)
    {
        $name = DB::table('aigc')->where('id', (int) $chatId)->value('name');

        return $name !== null && $name !== '' ? $name : '-';
    }
}
