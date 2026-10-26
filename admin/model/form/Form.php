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
 * 表单主表（form）
 */
class Form extends Model
{
    /** @var string */
    protected $table = 'form';

    /** @var string */
    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'captcha' => 'int',
        'require_login' => 'int',
        'spam_limit' => 'int',
        'status' => 'int',
        'sort' => 'int',
        'created_at' => 'datetime:Y-m-d H:i:s',
    );

    /**
     * 允许批量写入的字段白名单。
     *
     * @var array
     */
    protected $fillable = array(
        'name',
        'slug',
        'content',
        'success_message',
        'success_url',
        'captcha',
        'require_login',
        'spam_limit',
        'notify_email',
        'status',
        'sort',
        'created_at',
    );
}
