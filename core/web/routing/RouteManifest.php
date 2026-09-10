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

namespace Dou\Core\Web\Routing;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 路由清单（不可变可枚举数据源）
 *
 * RouteRules 负责底层 config/route.php 解析；RouteManifest 在其之上承载 home / static /
 * system_reserved 家族 / 风格规则 / 声明式条目的物化清单。
 *
 * 调用面：
 *   - 入站匹配：getEntries() 由 PrettyRouteMatcher 迭代消费（home / static / system_reserved /
 *     家族 / declared，不含 page/column/simple meta 模板——后者已展开为具体 declared 条目）。
 *   - 出站生成：getRuleGroups() 返回 page / column / simple 分组视图（与 RouteRules::getSelectedRuleGroups()
 *     字段结构相同），独立构建（不取自 getEntries），供 UrlBuilder 读 pattern；
 *     getEntryByName() 供具名反查（如 /user/book 等声明式条目）。
 *
 * 进程级缓存：首次访问时构建，clearCache() 重置（单测 / 配置热更后调用）。
 *
 * 详细字段契约见 docs/adr/2026-06-14-route-manifest-contract.md。
 */
class RouteManifest
{
    /** @var RouteEntry[]|null */
    private static $entries = null;

    /** @var array<string, int>|null name → entries 数组下标 */
    private static $nameIndex = null;

    /** @var array|null page / column / simple 分组视图（供 UrlBuilder 出站生成） */
    private static $ruleGroups = null;

    /**
     * 清空进程内缓存（单测 / 配置热更后调用）。
     *
     * @return void
     */
    public static function clearCache()
    {
        self::$entries = null;
        self::$nameIndex = null;
        self::$ruleGroups = null;
    }

    /**
     * 全部 manifest 条目（按匹配器迭代顺序）。
     *
     * @return RouteEntry[]
     */
    public static function getEntries()
    {
        self::ensureBuilt();
        return self::$entries;
    }

    /**
     * 按 route_type 过滤的条目（保持原顺序）。
     *
     * @param string $type 'home' | 'static' | 'system_reserved' | 'family' | 'page' | 'column' | 'simple' | 'declared'
     * @return RouteEntry[]
     */
    public static function getEntriesByType($type)
    {
        self::ensureBuilt();
        $result = array();
        foreach (self::$entries as $e) {
            if ($e->route_type === $type) {
                $result[] = $e;
            }
        }
        return $result;
    }

    /**
     * UrlBuilder 出站生成用的 page / column / simple 分组视图。
     *
     * 仅含 page / column / simple meta 模板（家族条目 / 具名条目 / home / static 不进此视图）。
     *
     * @return array {page?: array, column?: array, simple?: array}
     */
    public static function getRuleGroups()
    {
        if (self::$ruleGroups !== null) {
            return self::$ruleGroups;
        }
        $builder = new RouteManifestBuilder();
        return self::$ruleGroups = $builder->buildRuleGroups();
    }

    /**
     * 按具名反查条目（承载 /user/book 等声明式条目的具名反查）。
     *
     * @param string $name 路由名（点分），如 'book.user'
     * @return RouteEntry|null 未命中返回 null
     */
    public static function getEntryByName($name)
    {
        self::ensureBuilt();
        if (!isset(self::$nameIndex[$name])) {
            return null;
        }
        return self::$entries[self::$nameIndex[$name]];
    }

    /**
     * 是否已构建（外部诊断用，避免触发构建）。
     *
     * @return bool
     */
    public static function isBuilt()
    {
        return self::$entries !== null;
    }

    /**
     * 触发构建（内部用）。
     *
     * @return void
     */
    private static function ensureBuilt()
    {
        if (self::$entries !== null) {
            return;
        }

        $builder = new RouteManifestBuilder();
        self::$entries = $builder->build();

        self::$nameIndex = array();
        foreach (self::$entries as $idx => $entry) {
            if ($entry->name === null || $entry->name === '') {
                continue;
            }
            // name 唯一性由 devtools/route-list.php 在启动期断言；此处遵循「先注册先生效」，
            // 与 home / static / system_reserved 家族条目的内置 name 相容。
            if (!isset(self::$nameIndex[$entry->name])) {
                self::$nameIndex[$entry->name] = $idx;
            }
        }
    }
}
