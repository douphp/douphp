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

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Module\ModuleModelResolver;
use Dou\Core\Support\CategoryIds;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 分类树 + 旗下内容（list / child）终结型静态方法 withItems()，直返成品数组。
 *
 * module 由分类表名推导（article_category -> article）；内容行走对应内容 Model 的
 * published / with('category') / forUser / filterByCategory 单产线，由 accessor + $appends
 * 在水合阶段产出 Presenter 字段。
 *
 * 进程内 static cache key 包含 locale()->pack()，避免多语言切换脏读。
 * 内容 Model 未命中或缺 scopeFilterByCategory 时静默降级为「纯分类树 + list=[]」。
 */
trait HasCategoryWithItems
{
    /**
     * 宿主必须是 Model 子类（提供表名）。
     *
     * @return string
     */
    abstract public function getTable();

    /**
     * 分类树 + 每分类前 $itemNumber 条内容（带 list / child）。
     *
     * @param int $itemNumber 每分类内容条数（0 表示不附内容）
     * @param int|null $parentId 起始父级 id（null 等价 0）
     * @param bool $child true 时递归挂载 child 子树
     * @param int $userId 当前会员 ID（仅对 hasUserFields=true 的内容 Model 生效）
     * @return array
     */
    public static function withItems($itemNumber = 5, $parentId = null, $child = false, $userId = 0)
    {
        $self = new static();
        $table = $self->getTable();
        $module = preg_replace('/_category$/', '', $table);
        $itemNumber = (int) $itemNumber;
        $userId = (int) $userId;
        $child = (bool) $child;
        if ($parentId === null) {
            $parentId = 0;
        }
        $parentId = (int) $parentId;

        $categories = self::withItemsCategoryRows($table);

        $catIdsNeedItems = self::withItemsCollectCatIds($categories, $parentId, $itemNumber);

        $groupedItems = ($itemNumber > 0 && !empty($catIdsNeedItems))
            ? self::withItemsContentRows($module, $catIdsNeedItems, $itemNumber, $userId)
            : array();

        return self::withItemsBuildTree($categories, $module, $parentId, $itemNumber, $child, $groupedItems);
    }

    /**
     * 表内全部分类行（进程内静态缓存，按 table + locale 维度）；预热 name 多语言。
     *
     * @param string $table
     * @return array
     */
    private static function withItemsCategoryRows($table)
    {
        static $cache = array();
        $key = $table . '|' . locale()->pack();
        if (!isset($cache[$key])) {
            $cache[$key] = DB::table($table)
                ->order('sort ASC, id ASC')
                ->select();
            language()->warmup($table, array_column((array) $cache[$key], 'id'), 'name');
        }

        return $cache[$key];
    }

    /**
     * 收集起始 parent_id 下（含全部子孙）所有分类的 id，用于 IN 取内容。
     *
     * @param array $categories 全部分类行（未使用，保留以维持主流程签名稳定）
     * @param int $parentId 起始父级
     * @param int $itemNumber 0 时不收集（视图层不需 list）
     * @return array<int, int>
     */
    private static function withItemsCollectCatIds(array $categories, $parentId, $itemNumber)
    {
        if ($itemNumber <= 0) {
            return array();
        }
        $self = new static();

        return CategoryIds::children($self->getTable(), (int) $parentId);
    }

    /**
     * 走内容 Model 单产线取分类下内容；按 category_id 分组 + array_slice 限 $itemNumber。
     *
     * 未命中 Model 或缺 scopeFilterByCategory 时返回空数组（list 字段统一为 []）。
     *
     * @param string $module
     * @param array<int, int> $catIds
     * @param int $itemNumber
     * @param int $userId
     * @return array<int, array<int, array<string, mixed>>>
     */
    private static function withItemsContentRows($module, array $catIds, $itemNumber, $userId)
    {
        static $cache = array();
        $cacheKey = $module . '|' . $userId . '|' . $itemNumber . '|' . md5(implode(',', $catIds)) . '|' . locale()->pack();
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $resolver = app(ModuleModelResolver::class);
        $cls = $resolver->modelClassFor($module);
        if ($cls === null || !method_exists($cls, 'scopeFilterByCategory')) {
            $cache[$cacheKey] = array();
            return $cache[$cacheKey];
        }

        $query = $cls::query();
        if (method_exists($cls, 'scopePublished')) {
            $query = $query->published();
        }
        $query = $query->with('category');
        if (method_exists($cls, 'scopeForUser')) {
            // 即便 $userId === 0 也调 forUser：未登录态下负责把 price 格式化并补 sale_price，仅跳过 favorites 注入。
            $query = $query->forUser($userId);
        }
        $rows = $query->filterByCategory($catIds)
            ->order('id DESC')
            ->get()
            ->toArray();

        $grouped = array();
        foreach ((array) $rows as $row) {
            if (!isset($row['category_id'])) {
                continue;
            }
            $grouped[(int) $row['category_id']][] = $row;
        }
        foreach ($grouped as $cid => $list) {
            if (count($list) > $itemNumber) {
                $grouped[$cid] = array_slice($list, 0, $itemNumber);
            }
        }

        $cache[$cacheKey] = $grouped;

        return $grouped;
    }

    /**
     * 递归构造分类树并挂 list / child；$child 为 false 时 child 字段统一 ''（保持现有主题判空习惯）。
     *
     * @param array $categories
     * @param string $module
     * @param int $parentId
     * @param int $itemNumber
     * @param bool $child
     * @param array $groupedItems category_id => 已 slice 的内容行
     * @return array
     */
    private static function withItemsBuildTree(array $categories, $module, $parentId, $itemNumber, $child, array $groupedItems)
    {
        $tree = array();
        foreach ($categories as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((int) $row['parent_id'] !== (int) $parentId) {
                continue;
            }
            $row = language()->langBox($row, $module . '_category', 'name');
            $catId = (int) $row['id'];
            $item = array(
                'category_id' => $catId,
                'name' => isset($row['name']) ? $row['name'] : '',
                'icon' => (isset($row['icon']) && $row['icon']) ? attachment()->url($row['icon']) : '',
                'slug' => isset($row['slug']) ? $row['slug'] : '',
                'url' => route($module . '.category', array('category_id' => $catId)),
            );

            if ($itemNumber > 0 && isset($groupedItems[$catId])) {
                $item['list'] = $groupedItems[$catId];
            } else {
                $item['list'] = array();
            }

            if ($child) {
                $item['child'] = self::withItemsBuildTree($categories, $module, $catId, $itemNumber, $child, $groupedItems);
            } else {
                $item['child'] = '';
            }

            $tree[] = $item;
        }

        return $tree;
    }
}
