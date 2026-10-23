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
 * Release Date: 2026-10-12
 */

namespace Dou\Api\Request\Article;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 开放平台文章发布表单请求（store / update 场景复用）。
 *
 * 角色与后台 ArticleFormRequest 相同：定义校验规则并充当 validated() 的字段白名单键集合。
 * 开放端语义差异：
 * - 无 draft_token / image / created_at：草稿认领与主图属后台会话链路，创作时间由服务端取当前时间；
 * - category 支持「分类 id（数字）」或「分类 slug（字符串）」双形态，允许缺省
 *   （缺省时由发布服务按 open.default_category 解析）；
 * - slug 仅在 features.slug 开启时要求（unique 排除自身依赖 validationData() 并入的 id）。
 */
class ArticlePublishFormRequest extends FormRequest
{
    /**
     * 提供校验数据源：更新场景 id 取自路径（REST 资源语义），并入校验数据供 unique 规则排除自身。
     *
     * @return array
     */
    protected function validationData()
    {
        $data = parent::validationData();

        // Check::integer 仅接受数字字符串：路径参数取回的原生 int 与 JSON 数字形态统一
        // 回铸为字符串，避免「本就是整数」的 id / sort 被 integer 规则误拒。
        if ($this->scene === 'update' && request()) {
            $data['id'] = (string) request()->route('id', 0);
        }
        if (isset($data['sort']) && is_int($data['sort'])) {
            $data['sort'] = (string) $data['sort'];
        }

        return $data;
    }

    /**
     * 字段白名单 + 校验规则（同时是 validated() 的键集合）。
     *
     * @return array
     */
    public function rules()
    {
        $rules = array(
            'title' => '',
            'content' => '',
            'category' => '',
            'slug' => '',
            'keywords' => 'max:255',
            'description' => '',
            'sort' => 'integer|min_value:0|max_value:65535',
            'status' => 'in:0,1',
            'content_format' => $this->contentFormatRule(),
            // 仅用于控制正文远程图片本地化的行为开关，不是 article 表字段
            'content_remote_image_local' => 'boolean',
        );

        if ($this->scene === 'update') {
            $rules['id'] = 'required|integer|min_value:1';
            $rules['title'] = 'max:150';
        } else {
            $rules['title'] = 'required|max:150';
            $rules['content'] = 'required';
        }

        if (!empty(Config::get('features.slug', false))) {
            $rules['slug'] = $this->scene === 'update'
                ? 'required|slug|unique:article,slug,id'
                : 'required|slug|unique:article,slug';
        }

        return $rules;
    }

    /**
     * content_format 允许值来自 open 平台设置（dou_parameter group=open；open 未装时取内置默认 html,markdown）。
     *
     * @return string
     */
    private function contentFormatRule()
    {
        $formats = (array) Config::get('open.content_formats', array('html', 'markdown'));
        $formats = array_filter(array_map('trim', $formats), 'strlen');
        if (empty($formats)) {
            return '';
        }

        return 'in:' . implode(',', $formats);
    }
}
