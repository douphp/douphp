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

namespace Dou\Admin\Request\Miniprogram;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序后台导航表单（store / update）
 */
class MiniprogramNavFormRequest extends FormRequest
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
            'nav_menu' => 'max:500',
            'guide' => 'max:500',
            'type' => 'required|max:64',
            'sort' => 'integer',
        );

        if ($this->scene === 'update') {
            $rules['id'] = 'required|integer|min_value:1';
            $rules['status'] = 'integer';
        }

        return $rules;
    }
}
