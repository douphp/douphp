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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台活跃态解析器：以命中路由名为唯一事实源，集中计算子菜单与侧栏的布尔状态。
 *
 * 输出契约（{@see self::resolve()} 返回的 $nav）：
 *   - route_name      string 当前命中的路由名
 *   - sub_menu        null | array('key','title','icon','items')；
 *                     items 项为 array('key','name','url','is_active')，url 已绝对化
 *   - side            array(id => array('is_active','is_all_active','is_category_active))；
 *                     含全部侧栏节点（未命中者恒 false），is_all_active / is_category_active
 *                     仅栏目模块节点有意义（对应侧栏「全部 / 分类」二级项）
 *   - side_active_id  string 当前激活的侧栏节点 id，无则 ''
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
 *   4. 页面级 override（sub_menu / sub_item / side）以 array_key_exists 区分
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
     * @param array  $overrides 页面级覆盖：sub_menu|null、sub_item|null、side|null
     * @return array
     */
    public static function resolve($routeName, array $overrides = array())
    {
        $routeName = (string) $routeName;

        $families = array();
        $candidates = array();
        foreach (AdminMenuRegistry::subMenus() as $familyKey => $family) {
            $items = self::expandItems($familyKey, $family);
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

        if (array_key_exists('sub_menu', $overrides)) {
            $subMenuKey = $overrides['sub_menu'];
            $activeItemKey = null;
        }
        if (array_key_exists('sub_item', $overrides)) {
            $activeItemKey = $overrides['sub_item'];
        }

        $side = self::resolveSide($routeName, $overrides);

        return array(
            'route_name' => $routeName,
            'sub_menu' => ($subMenuKey !== null && isset($families[$subMenuKey]))
                ? self::buildSubMenu($families[$subMenuKey], $subMenuKey, $activeItemKey)
                : null,
            'side' => $side['state'],
            'side_active_id' => $side['active_id'],
        );
    }

    /**
     * 未派发（无路由事实）时的空契约，供入口兜底 assign。
     *
     * sub_menu 为 null；side 仍返回全量节点状态表（恒 false），保证模板固定点链
     * `$nav.side.<id>.is_active`（如 dou_msg 提示页内嵌侧栏）不出现未定义键。
     *
     * @return array
     */
    public static function emptyNav()
    {
        $side = self::resolveSide('', array());

        return array(
            'route_name' => '',
            'sub_menu' => null,
            'side' => $side['state'],
            'side_active_id' => '',
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
     * 展开族的项列表：user_center 占位项按配置 + 语言包动态展开；
     * 普通项补齐 renders（缺省=所在族）与 when / exclude / params / match 缺省值。
     *
     * @param string $familyKey
     * @param array  $family
     * @return array
     */
    private static function expandItems($familyKey, array $family)
    {
        $items = array();
        foreach ((array) $family['items'] as $item) {
            if (isset($item['source']) && $item['source'] === 'user_center') {
                foreach (self::userCenterItems($familyKey) as $entry) {
                    $items[] = $entry;
                }
                continue;
            }
            if (!isset($item['key'])) {
                continue;
            }
            $item['match'] = isset($item['match']) ? (array) $item['match'] : array();
            $item['exclude'] = isset($item['exclude']) ? (array) $item['exclude'] : array();
            $item['when'] = isset($item['when']) ? (array) $item['when'] : array();
            $item['params'] = isset($item['params']) ? (array) $item['params'] : array();
            $item['renders'] = isset($item['renders']) ? $item['renders'] : $familyKey;
            $items[] = $item;
        }

        return $items;
    }

    /**
     * 展开会员中心相关模块项（module.link_user_center ∩ 语言包 xxx_manager）。
     *
     * 过滤与命名规则：仅保留存在语言包的模块项，名称回退链 xxx_manager → xxx；renders 按
     * {@see AdminMenuRegistry::userCenterFamilies()} 映射（缺省=占位项所在族）。
     *
     * @param string $familyKey 占位项所在族（renders 缺省回退）
     * @return array
     */
    private static function userCenterItems($familyKey)
    {
        $familyMap = AdminMenuRegistry::userCenterFamilies();
        $items = array();
        foreach ((array) Config::get('module.link_user_center') as $value) {
            $value = (string) $value;
            $managerKey = $value . '_manager';
            if (!lang_has($managerKey) || !lang($managerKey)) {
                continue;
            }
            $name = lang($managerKey);
            if (!$name && lang_has($value)) {
                $name = lang($value);
            }
            if (!$name) {
                $name = $value;
            }
            $items[] = array(
                'key' => 'uc_' . $value,
                'label' => $name,
                'link' => 'admin.' . $value,
                'params' => array(),
                'match' => array('admin.' . $value . '.*'),
                'exclude' => array(),
                'when' => array(),
                'renders' => isset($familyMap[$value]) ? $familyMap[$value] : $familyKey,
            );
        }

        return $items;
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
            $name = isset($item['label']) ? $item['label'] : lang($item['name']);
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
     * 计算侧栏全部节点的布尔状态。
     *
     * 节点集合 = 注册表静态节点（{@see AdminMenuRegistry::sideNodes()}）
     * + config 派生模块节点（{@see self::moduleSideNodes()}），全局 max specificity
     * 唯一激活；页面级 override('side') 清空后强制指定节点。
     *
     * @param string $routeName
     * @param array  $overrides
     * @return array ['state' => array, 'active_id' => string]
     */
    private static function resolveSide($routeName, array $overrides)
    {
        $nodes = array();
        foreach (AdminMenuRegistry::sideNodes() as $sectionNodes) {
            foreach ($sectionNodes as $id => $node) {
                $nodes[$id] = $node;
            }
        }
        foreach (self::moduleSideNodes() as $id => $node) {
            if (!isset($nodes[$id])) {
                $nodes[$id] = $node;
            }
        }

        $winnerId = null;
        $bestSpec = null;
        foreach ($nodes as $id => $node) {
            if (!AdminMenuRegistry::passWhen(isset($node['when']) ? (array) $node['when'] : array())) {
                continue;
            }
            $spec = self::matchSpecificity(
                $routeName,
                isset($node['match']) ? (array) $node['match'] : array(),
                isset($node['exclude']) ? (array) $node['exclude'] : array()
            );
            if ($spec !== null && ($bestSpec === null || $spec > $bestSpec)) {
                $bestSpec = $spec;
                $winnerId = $id;
            }
        }

        if (array_key_exists('side', $overrides)) {
            $forced = $overrides['side'];
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
     * （二级「分类」项高亮）。复合模块按下述规则附加模式：
     *   - product ← attribute 域（属性归商品，同时计入分类）；
     *   - ai ← ai_generate 域；chat ← chat_knowledge 域；weixin ← weixin_media 域；
     *   - user ← 会员中心各模块域（vip/point/money/withdraw/share/favorites）；
     *   - work ← book.work 域。
     *
     * @return array
     */
    private static function moduleSideNodes()
    {
        $noShow = (array) Config::get('module.no_show_menu');
        $extraMatch = array(
            'product' => array('admin.attribute.*'),
            'ai' => array('admin.ai_generate.*'),
            'chat' => array('admin.chat_knowledge.*'),
            'user' => array('admin.vip.*', 'admin.point.*', 'admin.money.*', 'admin.withdraw.*', 'admin.share.*', 'admin.favorites.*'),
            'work' => array('admin.book.work.*'),
            'weixin' => array('admin.weixin_media.*'),
        );

        $nodes = array();
        foreach ((array) Config::get('module.column_module') as $module) {
            $module = (string) $module;
            if ($module === '' || in_array($module, $noShow, true)) {
                continue;
            }
            $match = array('admin.' . $module . '.*');
            if (isset($extraMatch[$module])) {
                $match = array_merge($match, $extraMatch[$module]);
            }
            $categoryMatch = array('admin.' . $module . '.category.*');
            if ($module === 'product') {
                $categoryMatch[] = 'admin.attribute.*';
            }
            $nodes[$module] = array('match' => $match, 'category_match' => $categoryMatch);
        }
        foreach ((array) Config::get('module.single_module') as $module) {
            $module = (string) $module;
            if ($module === '' || in_array($module, $noShow, true) || isset($nodes[$module])) {
                continue;
            }
            $match = array('admin.' . $module . '.*');
            if (isset($extraMatch[$module])) {
                $match = array_merge($match, $extraMatch[$module]);
            }
            $nodes[$module] = array('match' => $match);
        }

        return $nodes;
    }
}
