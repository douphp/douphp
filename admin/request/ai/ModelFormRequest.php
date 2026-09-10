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

namespace Dou\Admin\Request\Ai;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * AI 模型表单校验（以供应商为主表的编辑表单）。
 */
class ModelFormRequest extends FormRequest
{
    /**
     * @return array
     */
    protected function validationData()
    {
        $data = parent::validationData();

        if ($this->scene === 'update' && !isset($data['id']) && request()) {
            $data['id'] = request()->integer('id', 0);
        }

        return $data;
    }

    /**
     * @return array
     */
    public function rules()
    {
        $codeRule = $this->scene === 'update'
            ? 'required|unique:ai_provider,code,id'
            : 'required|unique:ai_provider,code';

        $rules = array(
            'name' => 'required|max:200',
            'code' => $codeRule,
            'base_url' => '',
            'status' => 'integer',
            'sort' => 'integer',
            'config' => '',
            'keys' => '',
            'keys_delete' => '',
            'models' => '',
            'models_delete' => '',
        );

        if ($this->scene === 'update') {
            $rules['id'] = 'required|integer|min_value:1';
        }

        return $rules;
    }
}
