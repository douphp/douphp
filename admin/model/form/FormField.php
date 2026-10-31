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

use Dou\Admin\Model\Concerns\PurgesRelatedOnDelete;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 表单字段（form_field）
 */
class FormField extends Model
{
    use PurgesRelatedOnDelete;

    /** @var string */
    protected $table = 'form_field';

    /** @var string */
    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'form_id' => 'int',
        'width' => 'int',
        'must_fill' => 'int',
        'limit' => 'int',
        'max_size' => 'int',
        'sort' => 'int',
    );

    /** @var array */
    protected $fillable = array(
        'form_id',
        'name',
        'slug',
        'type',
        'unit',
        'option',
        'placeholder',
        'tip',
        'default_value',
        'validate',
        'width',
        'must_fill',
        'limit',
        'extensions',
        'max_size',
        'sort',
    );
}
