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
 * 消息表（aigc_message）
 */
class AigcMessage extends Model
{
    protected $table = 'aigc_message';

    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'session_id' => 'int',
        'sender_id' => 'int',
        'model_id' => 'int',
        'prompt_tokens' => 'int',
        'completion_tokens' => 'int',
        'total_tokens' => 'int',
        'duration' => 'int',
        'has_error' => 'int',
    );

    /**
     * 按会话筛选。
     *
     * @param Builder $query
     * @param mixed $sessionId
     * @return Builder
     */
    public function scopeFilterBySessionId(Builder $query, $sessionId)
    {
        $sessionId = (int) $sessionId;
        if ($sessionId <= 0) {
            return $query;
        }

        return $query->where('session_id', $sessionId);
    }

    /**
     * 时间正序（会话阅读顺序）。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('id ASC');
    }
}
