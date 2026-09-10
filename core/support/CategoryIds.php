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

namespace Dou\Core\Support;

use Dou\Core\Facade\DB;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 分类 id 集合工具（无状态、三端共用、纯静态）。
 *
 * 用于列表 IN (category_id, ...) 类筛选；返回扁平 int[] 集合，不涉及展示与缓存合并。
 * 分类树 / 内容列表 / withItems 等装配见
 * {@see \Dou\Core\Model\Concerns\HasCategoryTree} 与
 * {@see \Dou\Front\Model\Concerns\HasCategoryWithItems}。
 *
 * 可选模块约定：传入的 $table 在 column_module 未安装站点上可能不存在；
 * 入口统一 DB::tableExist($table) 兜底，返回空集合，避免误查 *_category。
 *
 * 兼容 PHP 5.6+，仅依赖 DB 门面。
 */
class CategoryIds
{
    /**
     * $table 表中 $parentId 自身 + 全部子孙 id（去重，含父）。
     *
     * 表不存在或空表时退化为单元素数组 [parentId]，可直接喂给 IN 子句。
     *
     * @param string $table 分类表名（article_category / product_category 等）
     * @param int|string $parentId 起始父级 id
     * @return array<int, int>
     */
    public static function subtree($table, $parentId = 0)
    {
        $parentId = (int) $parentId;
        if (!is_string($table) || $table === '' || !DB::tableExist($table)) {
            return array($parentId);
        }
        $ids = self::children($table, $parentId);
        array_unshift($ids, $parentId);

        return array_values(array_unique($ids));
    }

    /**
     * $table 表中 $parentId 的全部子孙 id（不含自身）。
     *
     * @param string $table
     * @param int|string $parentId
     * @return array<int, int>
     */
    public static function children($table, $parentId = 0)
    {
        if (!is_string($table) || $table === '' || !DB::tableExist($table)) {
            return array();
        }

        $rows = self::tableRows($table);
        $acc = array();
        self::collectChildren($rows, (int) $parentId, $acc);

        return $acc;
    }

    /**
     * $table 表中 $catId 的祖先 parent_id 链（int[]，不含自身、不含 0）。
     *
     * 沿 *_category 表的 parent_id 自引用结构上溯，根分类（parent_id = 0）链尾即终止。
     *
     * @param string $table
     * @param int|string $catId
     * @return array<int, int>
     */
    public static function ancestors($table, $catId)
    {
        if (!is_string($table) || $table === '' || !DB::tableExist($table)) {
            return array();
        }

        $catId = (int) $catId;
        $chain = array();
        while ($catId > 0) {
            $row = DB::table($table)
                ->field('id, parent_id')
                ->where('id', $catId)
                ->find();
            if (!$row || (int) $row['parent_id'] <= 0) {
                break;
            }
            $parent = (int) $row['parent_id'];
            $chain[] = $parent;
            $catId = $parent;
        }

        return $chain;
    }

    /**
     * 全表行（仅 id, parent_id）按表名进程内缓存。
     *
     * @param string $table
     * @return array<int, array<string, mixed>>
     */
    private static function tableRows($table)
    {
        static $cache = array();
        if (!isset($cache[$table])) {
            $rows = DB::table($table)
                ->field('id, parent_id')
                ->order('sort ASC, id ASC')
                ->select();
            $cache[$table] = is_array($rows) ? $rows : array();
        }

        return $cache[$table];
    }

    /**
     * 在已加载的 rows 中递归收集 $parentId 下全部子孙 id 到 $acc。
     *
     * @param array $rows
     * @param int $parentId
     * @param array $acc
     * @return void
     */
    private static function collectChildren(array $rows, $parentId, array &$acc)
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((int) $row['parent_id'] === $parentId) {
                $childId = (int) $row['id'];
                $acc[] = $childId;
                self::collectChildren($rows, $childId, $acc);
            }
        }
    }
}
