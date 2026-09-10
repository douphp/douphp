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

namespace Dou\Admin\Request\Data;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * Data 表单请求（按场景复用）。
 */
class DataFormRequest extends FormRequest
{
    /**
     * 更新场景补充 id 到校验数据。
     *
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
     * 输入校验与白名单。
     *
     * @return array
     */
    public function rules()
    {
        $rules = array(
            'name' => 'required',
            'code' => 'required|alpha_dash',
            'parent_code' => 'alpha_dash',
            'data_group' => 'alpha',
            'data_item' => 'alpha_dash',
            'text' => '',
            'link' => '',
            'is_class' => 'in:0,1',
        );

        if ($this->scene === 'update') {
            $rules['id'] = 'required|integer|min_value:1';
        }

        return $rules;
    }
}
