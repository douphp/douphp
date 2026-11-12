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

namespace Dou\Admin\Request\Aigc;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * AI应用表单字段校验。
 */
class FieldFormRequest extends FormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        $rules = array(
            'app_id' => 'required|integer|min_value:1',
            'name' => 'required|max:150',
            'slug' => 'required|regex:/^[a-z][a-z0-9_]{0,59}$/',
            'type' => 'required|in:input,textarea,number,select,radio,checkbox',
            'option' => '',
            'placeholder' => 'max:255',
            'tip' => 'max:255',
            'default_value' => 'max:255',
            'must_fill' => 'in:0,1',
            'width' => 'integer|between_value:1,12',
            'sort' => 'integer|min_value:0',
            'status' => 'in:0,1',
        );

        if ($this->scene === 'update') {
            $rules['id'] = 'required|integer|min_value:1';
        }

        return $rules;
    }
}
