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

use Dou\Core\Support\Naming;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 风格规则展开器（meta 模板 → 具名 declared 条目）
 *
 * 用于在路由声明文件里把「当前选中风格的 page / column / simple 规则」按指定模块名展开成具体的
 * declared 条目：将 `{module}` 占位符替换为具体模块名，动作（index / show）依规则是否携带
 * target 派生（沿用 PrettyRouteMatcher 的派生逻辑：携带 target → 列表 index；否则 → 详情 show）。
 *
 * 与 PrettyRouteMatcher 的 meta 分支语义等价：
 *   - column 模块（如 product）每条 column 规则展开为一条 declared 条目（{module} → 'product'）
 *   - simple 模块（如 book）同 column 思路展开 simple 规则：由第一条匹配
 *     {module}/{action} 的规则展开成「逐 action 一条」，由调用方在路由文件里枚举
 *     （不在本展开器里枚举所有可能 action）
 *   - page 模块从 page 风格规则展开
 *
 * 关键设计：
 *   - 本类不做 ModuleRegistry 准入校验（声明文件本身就是「模块是这一类的」断言）
 *   - 默认 controller FQCN 由调用方传入；不做约定式 fallback
 *   - 风格切换在每次构建 manifest 时生效（route_custom.php / site.route_* 改变后 clearCache 即可）
 */
class StyleRuleExpander
{
    /**
     * 展开当前选中 column 风格的全部规则，为指定 column 模块生成 declared 条目。
     *
     * 每条规则展开为一条 entry：
     *   - 规则有 target（{module}_category 等） → action='index'（列表 / 分类列表）
     *   - 规则无 target → action='show'（详情）
     *
     * @param string $module 模块名（如 'product'）
     * @param string $controllerFqcn 控制器 FQCN（不含前导反斜杠归一化由调用方处理）
     * @param string $sourcePrefix RouteEntry::source 前缀（如 'declared:front/route/product.php'）
     * @return RouteEntry[] 同步保留规则顺序
     */
    public static function expandColumn($module, $controllerFqcn, $sourcePrefix)
    {
        $groups = RouteRules::getSelectedRuleGroups();
        $rules = isset($groups['column']) ? $groups['column'] : array();
        return self::expandRules($module, $controllerFqcn, $sourcePrefix, $rules, 'column');
    }

    /**
     * 展开当前选中 simple 风格的全部规则，为指定 simple 模块生成 declared 条目。
     *
     * 与 column 同：规则有 target → action='index'；无 target → 视为非详情，亦按 'index' 处理
     * （simple 模板规则当前无 target 字段；详情走 {module}/{id} 形态，action 仍由 controller 端
     * show 方法承接 —— 与 PrettyRouteMatcher 的 simple 分支 show 派生保持一致）。
     *
     * @param string $module
     * @param string $controllerFqcn
     * @param string $sourcePrefix
     * @return RouteEntry[]
     */
    public static function expandSimple($module, $controllerFqcn, $sourcePrefix)
    {
        $groups = RouteRules::getSelectedRuleGroups();
        $rules = isset($groups['simple']) ? $groups['simple'] : array();
        return self::expandRules($module, $controllerFqcn, $sourcePrefix, $rules, 'simple');
    }

    /**
     * 展开当前选中 page 风格的全部规则，为 page 模块生成 declared 条目。
     *
     * page 规则一律 module_fixed='page'，无 {module} 占位符；action 固定 'show'。
     *
     * @param string $controllerFqcn
     * @param string $sourcePrefix
     * @return RouteEntry[]
     */
    public static function expandPage($controllerFqcn, $sourcePrefix)
    {
        $groups = RouteRules::getSelectedRuleGroups();
        $rules = isset($groups['page']) ? $groups['page'] : array();
        $ctrl = '\\' . ltrim((string) $controllerFqcn, '\\');

        $entries = array();
        foreach ($rules as $idx => $rule) {
            if (!isset($rule['pattern'])) {
                continue;
            }
            $name = 'page.style.' . $idx;
            $entries[] = new RouteEntry(array(
                'name' => $name,
                'route_type' => 'declared',
                'pattern' => (string) $rule['pattern'],
                'params' => isset($rule['params']) && is_array($rule['params']) ? $rule['params'] : array(),
                'module' => 'page',
                'controller' => $ctrl,
                'action' => 'show',
                'sub' => null,
                'is_short_url_aware' => false,
                'is_family' => false,
                'source' => $sourcePrefix . ':' . $name,
            ));
        }
        return $entries;
    }

    /**
     * 通用规则展开：把 {module} 占位符替换为具体模块名，依 target 派生 action。
     *
     * @param string $module
     * @param string $controllerFqcn
     * @param string $sourcePrefix
     * @param array $rules 风格规则数组（来自 RouteRules::getSelectedRuleGroups）
     * @param string $typeTag 'column' | 'simple'（用于命名 disambiguation）
     * @return RouteEntry[]
     */
    private static function expandRules($module, $controllerFqcn, $sourcePrefix, array $rules, $typeTag)
    {
        $module = (string) $module;
        $ctrl = '\\' . ltrim((string) $controllerFqcn, '\\');
        // pattern 段用 URL 展示模块名（article → news），entry.module 仍存数据库模块名，
        // 与 UrlBuilder 出站生成（Naming::urlModule）及 dbModule 反推对齐。
        $urlModule = Naming::urlModule($module);

        $entries = array();
        foreach ($rules as $idx => $rule) {
            if (!isset($rule['pattern'])) {
                continue;
            }
            $pattern = str_replace('{module}', $urlModule, (string) $rule['pattern']);
            $params = isset($rule['params']) && is_array($rule['params']) ? $rule['params'] : array();
            // 占位符 module 已被字面替换，从 params 中移除
            if (isset($params['module'])) {
                unset($params['module']);
            }

            $hasTarget = isset($rule['target']);
            $action = $hasTarget ? 'index' : 'show';
            // simple 规则无 target 概念：路径段含 {action} / {sub_action} / {class} / {id} 等占位符
            // 时仍交由 controller 端按 declared action='index' 入口分发（show 形态由 {id:\d+}
            // 规则本身承载）。本展开器保持「有 target → index，无 target → show」二分法
            // 与 PrettyRouteMatcher 的派生逻辑近似（仅当无 target 且 pattern 含 {id:\d+} 时
            // 视为 show；其它无 target 形态 fallback 仍为 index 由 controller 端解释）。
            if ($typeTag === 'simple' && !$hasTarget && strpos($pattern, '{id:\\d+}') === false) {
                $action = 'index';
            }

            $name = $module . '.' . $typeTag . '.' . $idx;
            $entries[] = new RouteEntry(array(
                'name' => $name,
                'route_type' => 'declared',
                'pattern' => $pattern,
                'params' => $params,
                'module' => $module,
                'controller' => $ctrl,
                'action' => $action,
                'sub' => null,
                'is_short_url_aware' => ($typeTag === 'column'),
                'is_family' => false,
                'source' => $sourcePrefix . ':' . $name,
            ));
        }
        return $entries;
    }
}
