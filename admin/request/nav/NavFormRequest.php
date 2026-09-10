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

namespace Dou\Admin\Request\Nav;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 主导航表单请求（按场景复用）。
 *
 * 场景与控制器动作一一对应：store、update。
 */
class NavFormRequest extends FormRequest
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
        $rules = array(
            'name' => 'required|max:200',
            'type' => 'in:middle,top,bottom',
            'parent_id' => 'integer',
            'sort' => 'integer',
            'nav_menu' => '',
            'guide' => '',
            'icon' => '',
        );

        if ($this->scene === 'update') {
            $rules['id'] = 'required|integer|min_value:1';
            $rules['status'] = 'integer|between_value:0,1';
        }

        return $rules;
    }
}
