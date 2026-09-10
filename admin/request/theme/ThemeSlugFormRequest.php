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

namespace Dou\Admin\Request\Theme;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 主题启用/删除等 GET 携带的 slug（与 {@see \Dou\Core\Support\Check::extendId()} 语义一致）。
 */
class ThemeSlugFormRequest extends FormRequest
{

    /**
     * 合并查询参数中的 slug，供 enable/delete 使用。
     *
     * @return array
     */
    protected function validationData()
    {
        $data = parent::validationData();
        $slug = '';
        if (request()) {
            $slug = trim((string) request()->input('slug', ''));
        }
        $data['slug'] = $slug;

        return $data;
    }

    /**
     * @return array
     */
    public function rules()
    {
        return array(
            'slug' => 'required|regex:/^[A-Za-z0-9-_.]+$/',
        );
    }
}
