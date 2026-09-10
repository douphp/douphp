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

namespace Dou\Front\Model\Concerns;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * cate_info accessor：由已加载的 category 关联派生 {category_id, parent_id, name, url}。
 *
 * 关联未加载 / 无分类时返回 null。使用方须 with('category') 预加载并把 'cate_info' 列入 $appends。
 */
trait HasCateInfoAccessor
{
    /**
     * 分类信息（category_id / parent_id / name / url）。
     *
     * @return array|null
     */
    public function getCateInfoAttribute()
    {
        $category = $this->getAttribute('category');
        if (!$category) {
            return null;
        }

        $catId = (int) $category->getAttribute('id');

        return array(
            'category_id' => $catId,
            'parent_id' => (int) $category->getAttribute('parent_id'),
            'name' => $category->getAttribute('name'),
            'url' => route($this->getTable() . '.category', array('category_id' => $catId)),
        );
    }
}
