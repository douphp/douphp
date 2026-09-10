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

namespace Dou\Api\Request\Product;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序工作端商品表单请求（按场景复用 insert / update）。
 *
 * 字段白名单与校验规则与后台 ProductFormRequest 对齐，
 * 但移除仅后台关心的 created_at / slug 维度，
 * 以保持与小程序 add.js / edit.js 提交字段一致。
 */
class WorkProductFormRequest extends FormRequest
{
    /**
     * 提供校验数据源：在 POST 基础上补 id（更新场景）。
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
     * 工作端商品输入规则。
     *
     * 与 Admin\Model\Product\Product::$fillable 的字段集对齐，
     * 仅声明允许接收的输入项；持久化白名单仍以 fillable 为准。
     *
     * @return array
     */
    public function rules()
    {
        $rules = array(
            'category_id' => 'required|integer',
            'brand_id' => 'integer',
            'title' => 'required|max:150',
            'price' => 'required|price',
            'level_price' => '',
            'promote_price' => 'price',
            'stock' => 'integer',
            'defined' => '',
            'content' => '',
            'image' => '',
            'point' => 'integer',
            'keywords' => '',
            'description' => '',
            'sort' => 'integer',
        );

        if ($this->scene === 'update') {
            $rules['id'] = 'required|integer|min_value:1';
        }

        return $rules;
    }
}
