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

namespace Dou\Admin\Request\Page;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 单页表单请求（按 store / update 场景复用）。
 *
 * 由 PageController::store / update 注入，scene 对应 store、update。
 */
class PageFormRequest extends FormRequest
{
    /**
     * 提供校验数据源（在父类 POST 数据基础上按场景补充字段）。
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
     * 表单规则；与 Page::$fillable 分工：这里为可接收与校验的输入，含不入库控制项。
     *
     * @return array
     */
    public function rules()
    {
        $rules = array(
            'name' => 'required',
            'slug' => 'required|alpha_dash|unique:page,slug',
            'parent_id' => 'integer',
            'mode' => 'required|in:editor,visualize',
            'content' => '',
            'keywords' => '',
            'description' => '',
            'content_remote_image_local' => 'boolean',
            'draft_token' => '',
        );

        if ($this->scene === 'store') {
            $rules['draft_token'] = 'required|max:64';
        }

        if ($this->scene === 'update') {
            $rules['id'] = 'required|integer|min_value:1';
            $rules['slug'] = 'required|alpha_dash|unique:page,slug,id';
        }

        return $rules;
    }
}
