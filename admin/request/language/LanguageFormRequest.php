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

namespace Dou\Admin\Request\Language;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台语言请求校验（按场景复用）。
 *
 * 场景与控制器动作一一对应：
 * - store：新增
 * - update：编辑
 */
class LanguageFormRequest extends FormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        $rules = array(
            'name' => 'required',
            'language_pack' => 'required',
            'site_name' => 'required',
            'site_title' => 'required',
            'site_keywords' => '',
            'site_description' => '',
            'address' => '',
            'tel' => '',
            'fax' => '',
            'email' => '',
            'sort' => 'integer',
            'mode' => '',
        );

        if ($this->scene === 'update') {
            $rules['language_id'] = 'required|integer|min_value:1';
        }

        return $rules;
    }
}
