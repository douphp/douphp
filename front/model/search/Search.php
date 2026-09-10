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

namespace Dou\Front\Model\Search;

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Model;
use Dou\Core\Support\CategoryIds;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台全站栏目搜索模型。
 *
 * 在多个栏目模块表（如 article、product）上按关键字检索，通过 UNION ALL 合并结果；
 * 不负责单表 CRUD，故 {@see $table} 仅为满足 {@see BaseModel} 的占位表名。
 */
class Search extends Model
{
    /** @var string 占位表名；真实查询在 {@see buildModuleSearchQuery()} 中按模块动态指定 */
    protected $table = 'page';

    /**
     * 按栏目模块列表组装 UNION ALL 链式查询（未执行）。
     *
     * 各分支由 {@see buildModuleSearchQuery()} 生成；关键字为空或模块列表为空时返回 null。
     *
     * @param array $columnModules 参与搜索的模块名列表，例如 array('product', 'article')
     * @param string $keyword 搜索关键字（外层已校验时可传入）
     * @param int $catId 分类 ID；大于 0 时限定在该分类及其子分类内
     * @param string $langPack 语言包标识；非空则通过 language_value 按多语言标题匹配
     * @param string $orderSql 整体排序片段，不含 “ORDER BY”，例如 "created_at DESC, id DESC"
     * @return \Dou\Core\Infra\Database\Connection|null 链式查询对象，失败或无有效分支时为 null
     */
    public static function buildUnionSearchQuery($columnModules, $keyword, $catId, $langPack, $orderSql = '')
    {
        $columnModules = is_array($columnModules) ? $columnModules : array();
        $keyword = is_string($keyword) ? $keyword : '';
        $catId = (int) $catId;
        $langPack = is_string($langPack) ? $langPack : '';
        $orderSql = is_string($orderSql) ? trim($orderSql) : '';

        if ($keyword === '' || count($columnModules) === 0) {
            return null;
        }

        $queries = array();
        foreach ($columnModules as $module) {
            $module = is_string($module) ? trim($module) : '';
            if ($module === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $module)) {
                continue;
            }

            $query = static::buildModuleSearchQuery($module, $keyword, $catId, $langPack);
            if ($query !== null) {
                $queries[] = $query;
            }
        }

        if (empty($queries)) {
            return null;
        }

        $unionQuery = array_shift($queries);
        foreach ($queries as $query) {
            $unionQuery->union($query, true);
        }

        if ($orderSql !== '') {
            $unionQuery->order($orderSql);
        }

        return $unionQuery;
    }

    /**
     * 无关键词时按栏目模块列表组装 UNION ALL 链式查询（未执行）。
     *
     * 各分支由 {@see buildModuleListQuery()} 生成；模块列表为空时返回 null。
     *
     * @param array $columnModules 参与列表的模块名列表
     * @param int $catId 分类 ID；大于 0 时限定在该分类及其子分类内
     * @param string $orderSql 整体排序片段，不含 “ORDER BY”
     * @return \Dou\Core\Infra\Database\Connection|null
     */
    public static function buildUnionListQuery($columnModules, $catId, $orderSql = '')
    {
        $columnModules = is_array($columnModules) ? $columnModules : array();
        $catId = (int) $catId;
        $orderSql = is_string($orderSql) ? trim($orderSql) : '';

        if (count($columnModules) === 0) {
            return null;
        }

        $queries = array();
        foreach ($columnModules as $module) {
            $module = is_string($module) ? trim($module) : '';
            if ($module === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $module)) {
                continue;
            }

            $query = static::buildModuleListQuery($module, $catId);
            if ($query !== null) {
                $queries[] = $query;
            }
        }

        if (empty($queries)) {
            return null;
        }

        $unionQuery = array_shift($queries);
        foreach ($queries as $query) {
            $unionQuery->union($query, true);
        }

        if ($orderSql !== '') {
            $unionQuery->order($orderSql);
        }

        return $unionQuery;
    }

    /**
     * 为单个模块构造一条 SELECT 子查询，字段结构与其它分支对齐以便 UNION。
     *
     * 多语言：关联 language_value，按标题译文 LIKE 匹配。
     * 非多语言：标题或正文中 LIKE 匹配。
     * product 分支额外输出真实 price，其它模块 price 列为字面量 0。
     * 若表存在 status 字段则限定 status = 1。
     *
     * @param string $module 模块名（字母数字下划线），对应表前缀后的表名
     * @param string $keyword 原始关键字（内部拼接 %keyword%）
     * @param int $catId 分类筛选，逻辑同 {@see buildUnionSearchQuery()}
     * @param string $langPack 语言包，空表示单语言检索
     * @return \Dou\Core\Infra\Database\Connection 与各 UNION 分支字段对齐的链式 SELECT
     */
    private static function buildModuleSearchQuery($module, $keyword, $catId, $langPack)
    {
        $keywordPattern = '%' . $keyword . '%';
        $priceField = $module === 'product' ? 't.price AS price' : '0 AS price';

        $query = DB::table($module . ' AS t')
            ->field(
                "'" . $module . "' AS module, " .
                't.id, t.category_id, t.title AS title, t.content, t.description, t.image, t.created_at, t.sort, ' . $priceField
            );

        if ($langPack !== '') {
            $query->innerJoin('language_value AS l', 'l.item_id=t.id')
                ->where('l.module', $module)
                ->where('l.field', 'title')
                ->where('l.language_pack', $langPack)
                ->where('l.value', 'LIKE', $keywordPattern);
        } else {
            $query->where(function ($sub) use ($keywordPattern) {
                $sub->where('t.title', 'LIKE', $keywordPattern)
                    ->whereOr('t.content', 'LIKE', $keywordPattern);
            });
        }

        if ($catId > 0) {
            $categoryIdList = CategoryIds::subtree($module . '_category', $catId);
            if (is_array($categoryIdList) && count($categoryIdList) > 0) {
                $query->whereIn('t.category_id', array_map('intval', $categoryIdList));
            }
        }

        if (DB::fieldExist($module, 'status')) {
            $query->where('t.status', 1);
        }

        return $query;
    }

    /**
     * 无关键词时为单个模块构造列表 SELECT 子查询（字段与 {@see buildModuleSearchQuery()} 对齐）。
     *
     * @param string $module 模块名
     * @param int $catId 分类筛选
     * @return \Dou\Core\Infra\Database\Connection|null
     */
    private static function buildModuleListQuery($module, $catId)
    {
        $catId = (int) $catId;
        $priceField = $module === 'product' ? 't.price AS price' : '0 AS price';

        $query = DB::table($module . ' AS t')
            ->field(
                "'" . $module . "' AS module, " .
                't.id, t.category_id, t.title AS title, t.content, t.description, t.image, t.created_at, t.sort, ' . $priceField
            );

        if ($catId > 0) {
            $categoryIdList = CategoryIds::subtree($module . '_category', $catId);
            if (is_array($categoryIdList) && count($categoryIdList) > 0) {
                $query->whereIn('t.category_id', array_map('intval', $categoryIdList));
            }
        }

        if (DB::fieldExist($module, 'status')) {
            $query->where('t.status', 1);
        }

        return $query;
    }

    /**
     * 无结果时生成分页信息（供模板 pager 使用）。
     *
     * 占位表永假条件 + {@see Connection::paginate()}，总条数为 0，与列表页 pager 结构一致。
     *
     * @param int $pageSize 每页条数
     * @param int $page 当前页码
     * @param string $pageUrl 分页链接模板
     * @return array 与 {@see Connection::paginate()} 中 pager 键相同结构的数组
     */
    public static function runEmptySearchPager($pageSize, $page, $pageUrl)
    {
        $pageSize = (int) $pageSize > 0 ? (int) $pageSize : 10;
        $page = (int) $page > 0 ? (int) $page : 1;
        $pageUrl = is_string($pageUrl) ? $pageUrl : '';
        $result = static::where('id', -1)
            ->paginate($pageSize, $page, $pageUrl);

        return isset($result['pager']) && is_array($result['pager']) ? $result['pager'] : array();
    }

    /**
     * 对 UNION 链式查询分页并执行，返回列表与分页元数据。
     *
     * @param \Dou\Core\Infra\Database\Connection $query {@see buildUnionSearchQuery()} 的返回值
     * @param int $pageSize 每页条数
     * @param int $page 当前页码
     * @param string $pageUrl 分页链接模板
     * @return array 键 list：当前页行集；键 pager：分页数组。无效 query 时为 list 空、pager 空数组
     */
    public static function fetchUnionSearchRows($query, $pageSize, $page, $pageUrl)
    {
        $empty = array(
            'list' => array(),
            'pager' => array(),
        );
        $pageSize = (int) $pageSize > 0 ? (int) $pageSize : 10;
        $page = (int) $page > 0 ? (int) $page : 1;
        $pageUrl = is_string($pageUrl) ? $pageUrl : '';

        if (!is_object($query) || !method_exists($query, 'paginate')) {
            return $empty;
        }

        $result = $query->paginate($pageSize, $page, $pageUrl);
        if (!is_array($result)) {
            return $empty;
        }

        $list = isset($result['list']) && is_array($result['list']) ? $result['list'] : array();
        $pager = isset($result['pager']) && is_array($result['pager']) ? $result['pager'] : array();

        return array(
            'list' => $list,
            'pager' => $pager,
        );
    }
}
