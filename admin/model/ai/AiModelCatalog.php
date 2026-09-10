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
 * AI 模型目录表 ai_model（后台路由 ai/model）
 */
class AiModelCatalog extends Model
{
    protected $table = 'ai_model';

    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'provider_id' => 'int',
        'context_length' => 'int',
        'max_tokens' => 'int',
    );

    protected $fillable = array(
        'provider_id',
        'name',
        'model_code',
        'context_length',
        'max_tokens',
    );

    /**
     * 按 name / model_code 关键字模糊筛选；空字符串透传。
     *
     * @param Builder $query
     * @param mixed $keyword
     * @return Builder
     */
    public function scopeFilterByKeyword(Builder $query, $keyword)
    {
        $keyword = is_scalar($keyword) ? trim((string) $keyword) : '';
        if ($keyword === '') {
            return $query;
        }

        return $query->where(function ($query) use ($keyword) {
            $query->where('name', 'LIKE', '%' . $keyword . '%')
                ->whereOr('model_code', 'LIKE', '%' . $keyword . '%');
        });
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
     * @param mixed $providerId
     * @return string|null
     */
    public static function getProviderNameById($providerId)
    {
        if (!$providerId) {
            return null;
        }

        return DB::table('ai_provider')->where('id', (int) $providerId)->value('name');
    }

    /**
     * @param string $code
     * @return array|null
     */
    public static function findRowByModelCode($code)
    {
        return static::where('model_code', trim($code))->first();
    }

    /**
     * @param string $code
     * @param int $excludeId 0 表示不排除
     * @return bool
     */
    public static function existsOtherRowByModelCode($code, $excludeId)
    {
        $q = static::where('model_code', trim($code));
        if ($excludeId > 0) {
            $q->where('id', '<>', (int) $excludeId);
        }

        return (bool) $q->exists();
    }

    /**
     * @param mixed $modelId
     * @return int
     */
    public static function countUsageLogsForModel($modelId)
    {
        return DB::table('ai_log')->where('model_id', (int) $modelId)->count();
    }

    /**
     * @param mixed $modelId
     * @return int
     */
    public static function countChatSessionsForModel($modelId)
    {
        return DB::table('chat_session')->where('model_id', (int) $modelId)->count();
    }

    /**
     * @param mixed $modelId
     * @return int
     */
    public static function countChatMessagesForModel($modelId)
    {
        return DB::table('chat_message')->where('model_id', (int) $modelId)->count();
    }

    /**
     * @param mixed $modelId
     * @return int
     */
    public static function countApplicationsForModel($modelId)
    {
        return DB::table('ai')->where('model_id', (int) $modelId)->count();
    }
}
