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

namespace Dou\Admin\Request\Form;

use Dou\Core\Service\Form\FormFieldSchema;
use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 表单字段请求校验（按场景复用）。
 *
 * 场景与控制器动作一一对应：
 * - store：新增字段
 * - update：编辑字段（额外校验主键）
 *
 * 类型白名单直接取自 FormFieldSchema，避免前后端各维护一份清单。
 */
class FormFieldFormRequest extends FormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        $rules = array(
            'form_id' => 'required|integer|min_value:1',
            'name' => 'required|max:150',
            'slug' => 'required|regex:/^[a-z][a-z0-9_]{0,59}$/',
            'type' => 'required|in:' . implode(',', FormFieldSchema::keys()),
            'unit' => 'max:30',
            'option' => '',
            'placeholder' => 'max:255',
            'tip' => 'max:255',
            'default_value' => 'max:255',
            'validate' => 'max:255',
            'width' => 'integer|between_value:1,12',
            'must_fill' => 'in:0,1',
            'limit' => 'integer|between_value:0,50',
            'extensions' => 'max:255',
            'max_size' => 'integer|min_value:0',
            'sort' => 'integer|min_value:0',
        );

        if ($this->scene === 'update') {
            $rules['id'] = 'required|integer|min_value:1';
        }

        return $rules;
    }
}
