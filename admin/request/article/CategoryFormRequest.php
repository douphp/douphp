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

namespace Dou\Admin\Request\Article;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 文章分类表单请求（按场景复用）。
 */
class CategoryFormRequest extends FormRequest
{
    /**
     * 提供校验数据源（在父类 POST 数据基础上按场景补充字段）。
     *
     * 说明：
     * - 默认仅校验 POST 数据；更新场景下，category_id 常在路由/查询参数中传入，
     *   这里将其合并到校验数据，确保 rules() 中的 category_id 规则可被正确触发。
     * - 该方法只负责“组装待校验数据”，不做业务处理。
     *
     * @return array
     */
    protected function validationData()
    {
        $data = parent::validationData();

        // action 名（update）作为 scene 传给容器，用于区分场景
        if ($this->scene === 'update' && !isset($data['category_id']) && request()) {
            $data['category_id'] = request()->integer('category_id', 0);
        }

        return $data;
    }

    /**
     * 表单规则（请求层）：
     * - 定义输入校验规则（required/integer/alpha_dash/unique 等）。
     * - 同时作为 FormRequest::validated() 的字段白名单键集合。
     *
     * 与 ArticleCategory::$fillable 的分工：
     * - fillable 负责“哪些字段允许入库”（持久化层）。
     * - rules 负责“哪些输入可被接收与校验”（请求层）。
     *
     * @return array
     */
    public function rules()
    {
        $rules = array(
            'name' => 'required',
            'slug' => 'required|alpha_dash',
            'parent_id' => 'integer',
            'icon' => '',
            'keywords' => '',
            'description' => '',
            'sync_to_nav' => 'integer',
            'sort' => 'integer',
        );

        if ($this->scene === 'update') {
            $rules['category_id'] = 'required|integer|min_value:1';
            $rules['slug'] .= '|unique:article_category,slug,category_id';
        } else {
            $rules['slug'] .= '|unique:article_category,slug';
        }

        return $rules;
    }
}
