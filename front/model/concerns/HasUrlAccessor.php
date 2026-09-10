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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 列表项 url accessor：route($module . '.show', ['id' => $id])，模块名取自宿主表名。
 *
 * 使用方须把 'url' 列入 $appends（无对应原始字段）。
 */
trait HasUrlAccessor
{
    /**
     * 详情页 URL。
     *
     * @return string
     */
    public function getUrlAttribute()
    {
        return route($this->getTable() . '.show', array('id' => (int) $this->getKey()));
    }
}
