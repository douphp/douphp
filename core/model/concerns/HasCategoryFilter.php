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

namespace Dou\Core\Model\Concerns;

use Dou\Core\Orm\Builder;
use Dou\Core\Support\CategoryIds;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 按分类筛选（含全部子孙）。
 *
 * 单方法多态入参，统一覆盖单分类与多分类两种语义：
 * - int / 数字字符串：单分类 + 子孙
 * - array：多分类，每个分类各自的子孙合并去重
 * - 逗号字符串 '1,2,3'：等价 array
 * - 空 / 0 / 空数组：透传不落 where
 *
 * 分类表名由宿主 Model 的 `getTable() . '_category'` 推导，
 * 与 {@see HasCategoryTree} 反推习惯一致；
 * ID 拓扑展开委托 {@see \Dou\Core\Support\CategoryIds::subtree}。
 */
trait HasCategoryFilter
{
    /**
     * 宿主必须是 Model 子类（提供表名）。
     *
     * @return string
     */
    abstract public function getTable();

    /**
     * 按分类筛选（含子孙）。
     *
     * @param Builder $query
     * @param mixed $catIds int / 数字字符串 / 逗号字符串 / array
     * @return Builder
     */
    public function scopeFilterByCategory(Builder $query, $catIds)
    {
        if (is_string($catIds)) {
            $catIds = explode(',', $catIds);
        } elseif (!is_array($catIds)) {
            $catIds = array($catIds);
        }
        $catIds = array_values(array_unique(array_filter(array_map('intval', $catIds), function ($v) {
            return $v > 0;
        })));
        if (empty($catIds)) {
            return $query;
        }

        $table = $this->getTable() . '_category';
        $all = array();
        foreach ($catIds as $cid) {
            $all = array_merge($all, CategoryIds::subtree($table, $cid));
        }
        $all = array_values(array_unique(array_map('intval', $all)));

        return $query->where('category_id', 'IN', $all);
    }
}
