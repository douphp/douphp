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

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台表单主表请求（按场景复用）。
 *
 * 场景与控制器动作一一对应：
 * - store：新增（字段留到字段页维护；预置模板走 FormController::templateStore）
 * - update：编辑
 */
class FormFormRequest extends FormRequest
{
    /**
     * @return array
     */
    protected function validationData()
    {
        $data = parent::validationData();

        if ($this->scene === 'update' && !isset($data['form_id']) && request()) {
            $data['form_id'] = request()->integer('form_id', 0);
        }

        return $data;
    }

    /**
     * @return array
     */
    public function rules()
    {
        $rules = array(
            'name' => 'required|max:150',
            'slug' => 'required|regex:/^[a-z][a-z0-9_]{0,59}$/|unique:form,slug,form_id',
            'content' => '',
            'success_message' => 'max:255',
            'success_url' => 'max:255',
            'captcha' => 'in:0,1',
            'require_login' => 'in:0,1',
            'spam_limit' => 'integer|between_value:0,9999',
            'notify_email' => 'max:120|email',
            'status' => 'in:0,1',
            'sort' => 'integer|min_value:0',
        );

        if ($this->scene === 'update') {
            $rules['form_id'] = 'required|integer|min_value:1';
        }

        return $rules;
    }
}
