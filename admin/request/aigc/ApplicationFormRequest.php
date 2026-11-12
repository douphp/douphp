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

namespace Dou\Admin\Request\Aigc;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员 AI 应用表单校验（store / update 场景由路由动作名注入）。
 */
class ApplicationFormRequest extends FormRequest
{
    /**
     * 合并 Query 中的 id，供 update 场景 rules 使用。
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
     * 请求层规则与白名单键集合。
     *
     * @return array
     */
    public function rules()
    {
        $slugRule = $this->scene === 'update'
            ? 'required|slug|unique:aigc,slug,id'
            : 'required|slug|unique:aigc,slug';

        $rules = array(
            'name' => 'required|max:100',
            'slug' => $slugRule . '|max:50',
            'type' => 'required|in:chat,image,video,audio,ppt,excel',
            'description' => '',
            'icon' => '',
            'cover_image' => '',
            'model_ids' => 'required',
            'default_prompt' => '',
            'config' => '',
            'suggested_questions' => '',
            'is_free' => 'integer',
            'free_limit_type' => 'in:requests,tokens',
            'free_limit_value' => 'integer',
            'is_public' => 'integer',
            'sort' => 'integer',
            'status' => 'integer',
        );

        if ($this->scene === 'update') {
            $rules['id'] = 'required|integer|min_value:1';
        }

        return $rules;
    }
}
