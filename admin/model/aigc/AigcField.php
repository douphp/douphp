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
 * Release Date: 2026-11-11
 */

namespace Dou\Admin\Model\Aigc;

use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * AI应用表单字段（aigc_field）
 */
class AigcField extends Model
{
    protected $table = 'aigc_field';

    protected $primary = 'id';

    protected $casts = array(
        'id' => 'int',
        'app_id' => 'int',
        'must_fill' => 'int',
        'width' => 'int',
        'sort' => 'int',
        'status' => 'int',
    );

    protected $fillable = array(
        'app_id',
        'name',
        'slug',
        'type',
        'option',
        'placeholder',
        'tip',
        'default_value',
        'must_fill',
        'width',
        'sort',
        'status',
        'created_at',
        'updated_at',
    );
}
