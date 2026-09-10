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

namespace Dou\Front\Model\Concerns;

use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * created_at 派生 accessor：短日期（m-d）与日期分段（ymd / y / m / d）。
 *
 * 使用方须把 'add_time_short' / 'time' 列入 $appends。
 */
trait HasAddTimeAccessors
{
    /**
     * 短日期（m-d）。
     *
     * @return string
     */
    public function getAddTimeShortAttribute()
    {
        $ts = Util::toTimestamp($this->getRawAttribute('created_at'));

        return $ts !== null ? date('m-d', $ts) : '';
    }

    /**
     * 日期分段（年月日）。
     *
     * @return array
     */
    public function getTimeAttribute()
    {
        $ts = Util::toTimestamp($this->getRawAttribute('created_at'));

        return $ts !== null ? Util::toDateParts($ts) : array();
    }
}
