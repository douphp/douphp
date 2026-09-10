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
 * AI 供应商表 ai_provider
 */
class AiProvider extends Model
{
    protected $table = 'ai_provider';

    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'status' => 'int',
        'sort' => 'int',
    );

    protected $fillable = array(
        'name',
        'code',
        'base_url',
        'status',
        'sort',
        'config',
    );

    /**
     * 按 name / code 关键字模糊筛选；空字符串透传。
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
                ->whereOr('code', 'LIKE', '%' . $keyword . '%');
        });
    }

    /**
     * 默认列表排序（sort ASC, id DESC）。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('sort ASC, id DESC');
    }

    /**
     * @param string $code
     * @param int $excludeId
     * @return bool
     */
    public static function codeExistsExcluding($code, $excludeId = 0)
    {
        $q = DB::table(static::tableName())->where('code', $code);
        if ($excludeId > 0) {
            $q->where('id', '<>', $excludeId);
        }

        return (bool) $q->find();
    }

    /**
     * @param int $providerId
     * @return int
     */
    public static function countChatSessionsForProvider($providerId)
    {
        if (!DB::tableExist('chat_session')) {
            return 0;
        }

        return DB::table('chat_session')->where('provider_id', (int) $providerId)->count();
    }

    /**
     * @param int $providerId
     * @return int
     */
    public static function countUsageLogsForProvider($providerId)
    {
        if (!DB::tableExist('ai_log')) {
            return 0;
        }

        return DB::table('ai_log')->where('provider_id', (int) $providerId)->count();
    }

    /**
     * 统计引用该供应商下属模型的 AI 应用数量。
     *
     * @param int $providerId
     * @return int
     */
    public static function countApplicationsForProvider($providerId)
    {
        $modelIds = DB::table('ai_model')->where('provider_id', (int) $providerId)->column('id');
        if (empty($modelIds)) {
            return 0;
        }

        return DB::table('ai')->whereIn('model_id', $modelIds)->count();
    }
}
