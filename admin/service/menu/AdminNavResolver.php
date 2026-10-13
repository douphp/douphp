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
 * Release Date: 2026-09-26
 */

namespace Dou\Admin\Service\Menu;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Support\Util;
use Dou\Core\Web\Routing\RouteManifest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台活跃态解析器：以命中路由名为唯一事实源，集中计算子菜单与侧栏的布尔状态。
 *
 * 输出契约（{@see self::resolve()} 返回的 $nav）：
 *   - route_name        string 当前命中的路由名
 *   - submenu           null | array('key','title','icon','items')；
 *                       items 项为 array('key','name','url','is_active')，url 已绝对化
 *   - sidebar           array(id => array('is_active','is_all_active','is_category_active))；
 *                       含全部侧栏节点（未命中者恒 false），is_all_active / is_category_active
 *                       仅栏目模块节点有意义（对应侧栏「全部 / 分类」二级项）
 *   - sidebar_active_id string 当前激活的侧栏节点 id，无则 ''
 *
 * 声明书写空间：侧栏节点与子菜单族声明里出现的字符串一律是「路由别名」（路由名去掉
 *   admin. 前缀，如 book.rule_slot），下述 pattern 形态由 {@see self::expandAliases()} 在
 *   解析时生成，声明文本不再手写 admin. 前缀与 .* 通配；仅已发布模块包的旧声明（元素
 *   已以 admin. 开头）走兼容通道原样保留。
 *
 * 匹配语义（pattern 语法见 {@see AdminMenuRegistry}）：
 *   - 段边界通配：`admin.user.*` 命中 admin.user 及任意层下级（`.` 为段边界，
 *     不命中 admin.user_log）；尾 `.*` 兼容基名本身；
 *   - 无通配为全名精确匹配；
 *   - 多模式竞争以「最长字面前缀」为 specificity 胜出（admin.item.category.* 胜过
 *     admin.item.*），平局取先声明者。
 *
 * 裁决算法：
 *   1. 全局收集全部族命中项，max specificity 者的 renders 字段决定渲染族
 *      （无命中或 renders 显式 null 则不渲染子菜单，如作品 work 域）；
 *   2. 激活项在渲染族「自身命中项」内重新裁决（跨族 renders 接管时同样适用，
 *      如会员族的分销入口命中时渲染分销族并激活分销族自身项）；
 *   3. 侧栏在「注册表静态节点 + config 派生模块节点」间全局 max specificity 唯一激活；
 *      无任何节点命中时回落到本次渲染的子菜单族名（族名即节点 id）——即「同一子菜单组内
 *      的页面高亮同一大节点」，不再依赖跨域归属声明；
 *   4. 页面级 override（submenu / subitem / sidebar）以 array_key_exists 区分
 *      「不覆盖」与「显式 null」，强制指定渲染族 / 激活项 / 侧栏节点。
 *
 * 本类为无状态纯函数实现：结果仅取决于入参，可在框架接线点（默认解析）与控制器
 * （页面级 override）重复调用。
 */
class AdminNavResolver
{
    /**
     * 解析路由名得到 $nav 契约。
     *
     * @param string $routeName 当前路由名（如 admin.user.contact.edit），空字符串=未派发
     * @param array  $overrides 页面级覆盖：submenu|null、subitem|null、sidebar|null
     * @return array
     */
    public static function resolve($routeName, array $overrides = array())
    {
        $routeName = (string) $routeName;

        $allFamilies = AdminMenuRegistry::submenus();
        $familyKeys = array_keys($allFamilies);

        $families = array();
        $candidates = array();
        foreach ($allFamilies as $familyKey => $family) {
            $items = self::familyItems($familyKey, $family, $familyKeys);
            $families[$familyKey] = array(
                'title' => $family['title'],
                'icon' => $family['icon'],
                'items' => $items,
            );

            foreach ($items as $item) {
                if (empty($item['match']) || !AdminMenuRegistry::passWhen($item['when'])) {
                    continue;
                }
                $spec = self::matchSpecificity($routeName, $item['match'], $item['exclude']);
                if ($spec !== null) {
                    $candidates[] = array(
                        'family' => $familyKey,
                        'key' => $item['key'],
                        'spec' => $spec,
                        'renders' => $item['renders'],
                    );
                }
            }
        }

        // 全局裁决渲染族：max specificity 者的 renders 决定渲染哪一个子菜单族
        $winner = null;
        foreach ($candidates as $candidate) {
            if ($winner === null || $candidate['spec'] > $winner['spec']) {
                $winner = $candidate;
            }
        }
        $subMenuKey = $winner !== null ? $winner['renders'] : null;

        // 激活项：在渲染族「自身命中项」内重新裁决（跨族 renders 接管时同样适用）
        $activeItemKey = null;
        if ($subMenuKey !== null) {
            $bestSpec = null;
            foreach ($candidates as $candidate) {
                if ($candidate['family'] !== $subMenuKey || $candidate['key'] === null) {
                    continue;
                }
                if ($bestSpec === null || $candidate['spec'] > $bestSpec) {
                    $bestSpec = $candidate['spec'];
                    $activeItemKey = $candidate['key'];
                }
            }
        }

        if (array_key_exists('submenu', $overrides)) {
            $subMenuKey = $overrides['submenu'];
            $activeItemKey = null;
        }
        if (array_key_exists('subitem', $overrides)) {
            $activeItemKey = $overrides['subitem'];
        }

        $sidebar = self::resolveSidebar($routeName, $overrides, $subMenuKey);

        return array(
            'route_name' => $routeName,
            'submenu' => ($subMenuKey !== null && isset($families[$subMenuKey]))
                ? self::buildSubMenu($families[$subMenuKey], $subMenuKey, $activeItemKey)
                : null,
            'sidebar' => $sidebar['state'],
            'sidebar_active_id' => $sidebar['active_id'],
        );
    }

    /**
     * 未派发（无路由事实）时的空契约，供入口兜底 assign。
     *
     * submenu 为 null；sidebar 仍返回全量节点状态表（恒 false），保证模板固定点链
     * `$nav.sidebar.<id>.is_active`（如 dou_msg 提示页内嵌侧栏）不出现未定义键。
     *
     * @return array
     */
    public static function emptyNav()
    {
        $sidebar = self::resolveSidebar('', array());

        return array(
            'route_name' => '',
            'submenu' => null,
            'sidebar' => $sidebar['state'],
            'sidebar_active_id' => '',
        );
    }

    /**
     * 路由名是否命中单个模式（段边界通配，尾 .* 兼容基名）。
     *
     * @param string $routeName
     * @param string $pattern
     * @return bool
     */
    public static function routeMatches($routeName, $pattern)
    {
        return self::patternSpecificity($routeName, $pattern) !== null;
    }

    /**
     * 模式命中权重：字面前缀长度；未命中返回 null。
     *
     * 通配 `base.*` 命中 base 本身或 base 的下层（以 `.` 为段边界）；无通配为全名精确匹配。
     *
     * @param string $routeName
     * @param string $pattern
     * @return int|null
     */
    private static function patternSpecificity($routeName, $pattern)
    {
        $pattern = (string) $pattern;
        if ($pattern === '' || $routeName === '') {
            return null;
        }
        if (substr($pattern, -1) === '*') {
            $base = rtrim(substr($pattern, 0, -1), '.');
            if ($base === '') {
                return null;
            }
            if ($routeName === $base || strpos($routeName, $base . '.') === 0) {
                return strlen($base);
            }
            return null;
        }

        return $routeName === $pattern ? strlen($pattern) : null;
    }

    /**
     * 多模式竞争的 specificity：exclude 命中即不匹配；取 match 中最大者。
     *
     * @param string $routeName
     * @param array  $match
     * @param array  $exclude
     * @return int|null
     */
    private static function matchSpecificity($routeName, array $match, array $exclude)
    {
        foreach ($exclude as $pattern) {
            if (self::patternSpecificity($routeName, $pattern) !== null) {
                return null;
            }
        }

        $bestSpec = null;
        foreach ($match as $pattern) {
            $spec = self::patternSpecificity($routeName, $pattern);
            if ($spec !== null && ($bestSpec === null || $spec > $bestSpec)) {
                $bestSpec = $spec;
            }
        }

        return $bestSpec;
    }

    /**
     * 归一族的项列表：别名串 / 数组声明统一展开为完整 item，并折叠族级挂载表。
     *
     * 公开供侧栏覆盖扫描门禁复用（devtools/admin-nav-coverage-scan.php），保证
     * 门禁与运行时走同一套推导规则。
     *
     * 族级 'also' 挂载表（键 = 无独立入口子域的路由别名，值 = 归属项的路由别名）把该
     * 子域的匹配模式追加进目标项且不生成菜单行，用于「规则时段挂在规则项下」这类钻取
     * 型子域，免得在 items 里多写一行或重复抄写目标项自身的模式。
     *
     * 目标项按别名定位（别名项允许覆盖 key，只比 key 会在覆盖时静默失效）；无别名的
     * 全字段项退回按 key 比对。
     *
     * @param string     $familyKey
     * @param array      $family
     * @param array|null $familyKeys 全部族名（缺省从注册表取，用于 renders 跳族推导）
     * @return array
     */
    public static function familyItems($familyKey, array $family, $familyKeys = null)
    {
        if ($familyKeys === null) {
            $familyKeys = array_keys(AdminMenuRegistry::submenus());
        }

        $items = array();
        $itemAliases = array();
        foreach ((array) $family['items'] as $item) {
            $normalized = self::normalizeItem($item, $familyKey, $familyKeys);
            if ($normalized !== null) {
                $items[] = $normalized;
                $itemAliases[] = rtrim(is_string($item) ? $item : (isset($item['alias']) ? (string) $item['alias'] : ''), '.*');
            }
        }

        foreach ((array) (isset($family['also']) ? $family['also'] : array()) as $subAlias => $ownerAlias) {
            $patterns = self::expandAliases(array($subAlias));
            if (empty($patterns)) {
                continue;
            }
            $ownerAlias = rtrim((string) $ownerAlias, '.*');
            $ownerKey = str_replace('.', '_', $ownerAlias);
            foreach ($items as $index => $item) {
                if ($itemAliases[$index] === $ownerAlias
                    || ($itemAliases[$index] === '' && $item['key'] === $ownerKey)) {
                    $items[$index]['match'][] = $patterns[0];
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * 归一单条 item 声明，三种写法：
     *   - 别名串（首选）：'user' / 'user.contact'，其余字段全部按约定推导；
     *   - 带 'alias' 的数组：先按别名推导，再用同层显式键覆盖 key / name / link / params /
     *     when / exclude / renders（不接受 match，跨域归属改由族级 'also' 挂载表表达）；
     *   - 全字段数组（无 alias）：不推导，仅补 name / when / params / renders 缺省，
     *     match / exclude 的元素同样按别名展开。
     *
     * 约定推导（A = 别名，B = A 的点转下划线）：key = B；link = admin.A；match = admin.A.*；
     * name = B.'_manager' 语言键存在则取之、否则取 B；renders = A 首段为其它已存在族名时
     * 指向该族（跳族接管），否则为本族。exclude 的元素按路由别名书写并展开为段边界通配。
     *
     * 兼容通道：模块包旧声明若仍写 match，其元素展开后追加到推导值之后（不替换），
     * 保证旧文件被覆盖回写时不会丢高亮；新声明一律不再写该键（空数组因此不再具备
     * 「抑制推导」的能力，旧包若靠它做纯链接行需改写为无 alias 的全字段数组）。
     *
     * 可见性：link 非空且该路由名未在路由清单注册（模块未安装即无路由声明文件）时返回
     * null 丢弃该项——会员中心等跳模块入口据此随装卸自动增减，无需改写声明文件；
     * link 与 match 均为空的无效声明同样丢弃。
     *
     * @param mixed  $item
     * @param string $familyKey
     * @param array  $familyKeys
     * @return array|null
     */
    private static function normalizeItem($item, $familyKey, array $familyKeys)
    {
        if (is_string($item)) {
            $item = array('alias' => $item);
        }
        if (!is_array($item)) {
            return null;
        }

        if (isset($item['alias'])) {
            $alias = (string) $item['alias'];
            unset($item['alias']);
        } else {
            $alias = '';
        }

        if ($alias !== '') {
            $base = str_replace('.', '_', $alias);
            $prefix = strpos($alias, '.') !== false ? substr($alias, 0, strpos($alias, '.')) : $alias;
            $patterns = array('admin.' . $alias . '.*');
            if (isset($item['match'])) {
                $patterns = array_merge($patterns, self::expandAliases($item['match']));
                unset($item['match']);
            }
            $derived = array(
                'key'     => $base,
                'name'    => lang_has($base . '_manager') ? $base . '_manager' : $base,
                'link'    => 'admin.' . $alias,
                'match'   => $patterns,
                'renders' => ($prefix !== $familyKey && in_array($prefix, $familyKeys, true)) ? $prefix : $familyKey,
            );
            if (isset($item['exclude'])) {
                $derived['exclude'] = self::expandAliases($item['exclude']);
                unset($item['exclude']);
            }
            $item = array_merge($derived, $item);
        }

        if (!isset($item['key'])) {
            return null;
        }
        if (!isset($item['name'])) {
            $item['name'] = $item['key'];
        }
        // 两种形式的 match / exclude 均在此收口头尾：别名形式已在上面展开过，元素都是
        // admin. 开头的模式串，展开器对它们原样透传，故此处重复施加是幂等的
        $item['match'] = isset($item['match']) ? self::expandAliases($item['match']) : array();
        $item['exclude'] = isset($item['exclude']) ? self::expandAliases($item['exclude']) : array();
        $item['when'] = isset($item['when']) ? (array) $item['when'] : array();
        $item['params'] = isset($item['params']) ? (array) $item['params'] : array();
        $item['renders'] = isset($item['renders']) ? $item['renders'] : $familyKey;

        // 既无指向又无匹配模式的声明为无效项（如旧版 source=user_center 占位声明被
        // 模块包覆盖写回时的形态），不参与匹配与渲染，避免多出一行不可点的死菜单
        if (empty($item['link']) && empty($item['match'])) {
            return null;
        }

        if (!empty($item['link']) && RouteManifest::getEntryByName($item['link']) === null) {
            return null;
        }

        return $item;
    }

    /**
     * 构建渲染族的子菜单视图数据（项按 when 过滤，name 经 lang() 求值为文本）。
     *
     * @param array       $family        展开后的族（title / icon / items）
     * @param string      $familyKey
     * @param string|null $activeItemKey 激活项 key
     * @return array
     */
    private static function buildSubMenu(array $family, $familyKey, $activeItemKey)
    {
        $items = array();
        foreach ($family['items'] as $item) {
            if (!AdminMenuRegistry::passWhen($item['when'])) {
                continue;
            }
            $name = lang($item['name']);
            $url = '';
            if (!empty($item['link'])) {
                $url = Util::absolutizeEntryUrl(route($item['link'], $item['params']));
            }
            $items[] = array(
                'key' => $item['key'],
                'name' => $name,
                'url' => $url,
                'is_active' => ($activeItemKey !== null && $item['key'] === $activeItemKey),
            );
        }

        return array(
            'key' => $familyKey,
            'title' => lang($family['title']),
            'icon' => $family['icon'],
            'items' => $items,
        );
    }

    /**
     * 别名 → 匹配模式展开：'book.rule_slot' 变为 'admin.book.rule_slot.*'（段边界通配，
     * 兼容基名本身）。声明文本只写别名，运行时模式串由本方法生成。
     *
     * 公开供注册表节点归一与覆盖扫描门禁复用；元素若已以 admin. 开头则视为完整模式串
     * 原样保留（已发布模块包旧声明的兼容通道）。别名误带通配尾巴（'ai.key.*'）时先剥掉
     * 再补，避免生成 admin.ai.key.*.* 这类永不命中的死模式，故本方法可重复施加。
     *
     * @param mixed $aliases 路由别名或别名数组
     * @return array
     */
    public static function expandAliases($aliases)
    {
        $patterns = array();
        foreach ((array) $aliases as $alias) {
            $alias = (string) $alias;
            if ($alias === '') {
                continue;
            }
            if (strpos($alias, 'admin.') === 0) {
                $patterns[] = $alias;
                continue;
            }
            $patterns[] = 'admin.' . rtrim($alias, '.*') . '.*';
        }

        return $patterns;
    }

    /**
     * 侧栏节点全集（已归一）：注册表静态节点 + config 派生模块节点，id 冲突时静态优先。
     *
     * 公开供覆盖扫描门禁复用，保证门禁与运行时看到同一份节点视图。
     *
     * @return array<string, array>
     */
    public static function sidebarNodes()
    {
        $nodes = array();
        foreach (AdminMenuRegistry::sidebarNodes() as $sectionNodes) {
            foreach ($sectionNodes as $id => $node) {
                $nodes[$id] = self::normalizeNode($node);
            }
        }
        foreach (self::moduleSidebarNodes() as $id => $node) {
            if (!isset($nodes[$id])) {
                $nodes[$id] = $node;
            }
        }

        return $nodes;
    }

    /**
     * 归一单个侧栏节点：匹配域 = link 推导的主域（节点指向的路由域即其主匹配域，声明里
     * 不必重复书写）+ 显式 'match' 里的附加兼管域（写别名）；exclude / category_match
     * 的元素同样按别名展开。
     *
     * @param array $node
     * @return array
     */
    private static function normalizeNode(array $node)
    {
        $patterns = array();
        if (!empty($node['link'])) {
            $link = (string) $node['link'];
            $patterns = self::expandAliases(array(strpos($link, 'admin.') === 0 ? substr($link, 6) : $link));
        }
        if (!empty($node['match'])) {
            $patterns = array_merge($patterns, self::expandAliases($node['match']));
        }
        $node['match'] = $patterns;

        $node['exclude'] = isset($node['exclude']) ? self::expandAliases($node['exclude']) : array();
        $node['category_match'] = isset($node['category_match']) ? self::expandAliases($node['category_match']) : array();

        return $node;
    }

    /**
     * 计算侧栏全部节点的布尔状态。
     *
     * 节点集合 = {@see self::sidebarNodes()}（注册表静态节点已归一 + config 派生模块节点），
     * 全局 max specificity 唯一激活；无节点命中时回落到本次渲染的子菜单族名（族名即节点 id）；
     * 页面级 override('sidebar') 优先级最高，清空后强制指定节点。
     *
     * @param string      $routeName
     * @param array       $overrides
     * @param string|null $familyKey 本次渲染的子菜单族名（无族命中传 null，不参与回落）
     * @return array ['state' => array, 'active_id' => string]
     */
    private static function resolveSidebar($routeName, array $overrides, $familyKey = null)
    {
        $nodes = self::sidebarNodes();

        $winnerId = null;
        $bestSpec = null;
        foreach ($nodes as $id => $node) {
            if (!AdminMenuRegistry::passWhen(isset($node['when']) ? (array) $node['when'] : array())) {
                continue;
            }
            $spec = self::matchSpecificity(
                $routeName,
                (array) (isset($node['match']) ? $node['match'] : array()),
                (array) (isset($node['exclude']) ? $node['exclude'] : array())
            );
            if ($spec !== null && ($bestSpec === null || $spec > $bestSpec)) {
                $bestSpec = $spec;
                $winnerId = $id;
            }
        }

        // 无任何节点命中时回落到渲染族名：族名即节点 id（同一子菜单组内页面高亮同一大节点）
        if ($winnerId === null && $familyKey !== null && isset($nodes[$familyKey])) {
            $winnerId = $familyKey;
        }

        if (array_key_exists('sidebar', $overrides)) {
            $forced = $overrides['sidebar'];
            $winnerId = ($forced !== null && isset($nodes[$forced])) ? $forced : null;
        }

        $state = array();
        foreach ($nodes as $id => $node) {
            $isActive = ($id === $winnerId);
            $isCategory = $isActive && isset($node['category_match'])
                && self::matchSpecificity($routeName, (array) $node['category_match'], array()) !== null;
            $state[$id] = array(
                'is_active' => $isActive,
                'is_all_active' => $isActive && !$isCategory,
                'is_category_active' => $isCategory,
            );
        }

        return array(
            'state' => $state,
            'active_id' => $winnerId !== null ? $winnerId : '',
        );
    }

    /**
     * config 派生的侧栏模块节点（column_module + single_module − no_show_menu）。
     *
     * 节点 id 即模块短名，匹配 `admin.<模块>.*`；栏目节点附 category_match
     * （二级「分类」项高亮）。跨域归属不在此声明：无独立节点的模块（如钱包、积分等
     * 会员中心入口）由其所属子菜单族的族名回落统一高亮（{@see self::resolveSidebar()}）。
     *
     * 公开供侧栏覆盖扫描门禁复用（devtools/admin-nav-coverage-scan.php）。
     *
     * @return array
     */
    public static function moduleSidebarNodes()
    {
        $noShow = (array) Config::get('module.no_show_menu');

        $nodes = array();
        foreach ((array) Config::get('module.column_module') as $module) {
            $module = (string) $module;
            if ($module === '' || in_array($module, $noShow, true)) {
                continue;
            }
            $nodes[$module] = array(
                'match' => array('admin.' . $module . '.*'),
                'category_match' => array('admin.' . $module . '.category.*'),
            );
        }
        foreach ((array) Config::get('module.single_module') as $module) {
            $module = (string) $module;
            if ($module === '' || in_array($module, $noShow, true) || isset($nodes[$module])) {
                continue;
            }
            $nodes[$module] = array('match' => array('admin.' . $module . '.*'));
        }

        return $nodes;
    }
}
