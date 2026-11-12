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
 * 会话表（aigc_session）
 */
class AigcSession extends Model
{
    protected $table = 'aigc_session';

    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'app_id' => 'int',
        'user_id' => 'int',
        'model_id' => 'int',
        'total_tokens' => 'int',
        'message_count' => 'int',
    );

    /**
     * 按应用筛选；0 / 空透传。
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
     * 按会话状态筛选（active/closed）；空透传。
     *
     * @param Builder $query
     * @param mixed $status
     * @return Builder
     */
    public function scopeFilterByStatus(Builder $query, $status)
    {
        $status = is_scalar($status) ? trim((string) $status) : '';
        if ($status === '') {
            return $query;
        }

        return $query->where('status', $status);
    }

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
     * 标题关键字模糊筛选；空透传。
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

        return $query->where('title', 'LIKE', '%' . $keyword . '%');
    }

    /**
     * 默认排序：最近活跃优先。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('updated_at DESC');
    }
}
