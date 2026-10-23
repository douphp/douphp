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

namespace Dou\Admin\Request\Metadata;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 扩展字段定义表单请求。
 */
class MetadataFormRequest extends FormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        return array(
            'id' => 'integer',
            'module' => 'required|max:30',
            'slug' => 'required|max:30',
            'label' => 'required|max:150',
            'type' => 'required|max:20',
            'options' => '',
            'extensions' => 'max:255',
            'max_kb' => 'integer',
            'required' => 'integer',
            'limit' => 'integer',
            'tip' => 'max:255',
            'sort' => 'integer',
            'status' => 'integer',
            'definition_language' => '',
        );
    }
}
