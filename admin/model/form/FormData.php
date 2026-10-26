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

namespace Dou\Admin\Model\Form;

use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 表单提交数据（form_data）
 */
class FormData extends Model
{
    /** @var string */
    protected $table = 'form_data';

    /** @var string */
    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'form_id' => 'int',
        'user_id' => 'int',
        'if_read' => 'int',
        'status' => 'int',
        'created_at' => 'datetime:Y-m-d H:i:s',
    );

    /** @var array */
    protected $fillable = array(
        'form_id',
        'data',
        'user_id',
        'ip',
        'source_url',
        'if_read',
        'status',
        'created_at',
    );
}
