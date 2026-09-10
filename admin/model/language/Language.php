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

namespace Dou\Admin\Model\Language;

use Dou\Core\Model\Concerns\HasDistinctValues;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台语言数据模型
 */
class Language extends Model
{
    use HasDistinctValues;

    protected $table = 'language';
    protected $primary = 'id';

    /**
     * 允许批量写入的字段白名单。
     *
     * @var array
     */
    protected $fillable = array(
        'name',
        'language_pack',
        'site_name',
        'site_title',
        'site_keywords',
        'site_description',
        'site_logo',
        'address',
        'tel',
        'fax',
        'email',
        'sort',
    );
}
