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
 * Release Date: 2026-10-21
 */

namespace Dou\Admin\Service\Menu;

use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台页面 Tab 注册表与解析器：Tab 即数据。
 *
 * 声明文件位于 admin/menu/tab/<page>.php（纯 return 组数组，组名 = 文件名），
 * 随核心发行同步；页面模板不再手写 tab 项。一个组可用 match 同时覆盖主页面与
 * 关联页（如 setting 组同时服务扩展字段、语言设置），关联页无需单独建组。
 * {@see self::groups()} 在首次解析时 glob 装配并做请求级缓存。
 *
 * 组字段：
 *   - match  命中的路由别名数组（可列多个，覆盖主页面与关联页；沿用
 *            AdminNavResolver::expandAliases() 的段边界通配约定，如
 *            'setting' / 'metadata' / 'language.system'）；
 *   - items  项声明数组，支持静态项与动态 source 两种形态；
 *   - home   主页面路由名（可选）：当前路由不是 home 时，锚点项自动改为指向
 *            home#anchor 的普通链接，使同一组 Tab 可被关联页面直接复用。
 *
 * 静态项字段：
 *   - key       组内唯一标识（缺省由 anchor / link 推导）；
 *   - name      语言键（显示名，解析时经 lang() 求值）；
 *   - anchor    页面内锚点 id；给出时渲染为 data-toggle="tab" 的锚点触发器；
 *   - link      指向路由名（route($link)）；anchor 为空时必填；
 *   - active    补充高亮路由别名（可选）：与 link 指向同一入口的其它路由名，
 *               如 metadata 资源 index 与 metadata.fields 共用「扩展字段」页；
 *   - params    route() 附加参数；
 *   - hash      普通链接末尾追加的锚点（如 language 页跳 admin.setting#main）；
 *   - when      可见条件（复用 AdminMenuRegistry::passWhen 的 feature / sign 等）；
 *   - when_var  上下文变量名，非空才渲染（如 parameter_system_list）；
 *   - config_group 该锚点项由一个 config 分组承载：供 SettingService 装配正文 pane，
 *                 Tab 与正文共用同一份清单。
 *
 * 动态 source 字段：
 *   - source.var        上下文变量名（config_list / lang_list 等）；
 *   - source.format     映射格式：
 *                         anchor       每项 name / lang → 主页 #name 锚点；关联页复用
 *                                      时改为主页分组锚点链接；
 *                         setting_link 每项 name / lang → admin.setting#name 普通链接；
 *                         lang         每项 language_pack / name → admin.language.system
 *                                      ?language_pack=xx，显示 name + lang('language_setting')；
 *   - source.active_var 可选，行内该字段为真时该项 is_active（如 cur）。
 *
 * 输出契约（模板 inc/tab_nav.tpl 消费）：
 *   null | array(
 *       'key'   => 组名,
 *       'items' => array<array{key: string, name: string, url: string, is_anchor: bool, is_active: bool}>
 *   )
 */
class AdminTabResolver
{
    /** @var array|null 装配缓存（请求级）：全部 Tab 组声明 */
    private static $groups = null;

    /**
     * Tab 组装配：glob admin/menu/tab/*.php，组名取文件名。
     *
     * 目录不存在或无文件时返回空数组；声明缺少 match / items 的文件静默跳过。
     *
     * @return array<string, array>
     */
    public static function groups()
    {
        if (self::$groups !== null) {
            return self::$groups;
        }

        $groups = array();
        $tabDir = ADMIN_PATH . 'menu/tab/';
        foreach ((array) glob($tabDir . '*.php') as $file) {
            $declared = include $file;
            if (is_array($declared) && isset($declared['match'], $declared['items'])) {
                $groups[basename($file, '.php')] = $declared;
            }
        }

        self::$groups = $groups;

        return $groups;
    }

    /**
     * 组内「config 分组」项键清单：静态锚点项中 config_group 为真的 key。
     *
     * 供设置页正文装配复用同一份声明（Tab 与 pane 同源），避免两处清单漂移。
     *
     * @param string $groupKey 组名（如 setting）
     * @return array<int,string>
     */
    public static function configGroupKeys($groupKey)
    {
        $groups = self::groups();
        if (!isset($groups[$groupKey])) {
            return array();
        }

        $keys = array();
        foreach ((array) $groups[$groupKey]['items'] as $item) {
            if (is_array($item) && !empty($item['config_group']) && isset($item['key'])) {
                $keys[] = (string) $item['key'];
            }
        }

        return $keys;
    }

    /**
     * 按当前路由名解析页面 Tab 组。
     *
     * 未命中任何声明组时返回 null，模板安全跳过；命中后按声明顺序组装项，
     * 条件不满足的项（when / when_var）与无效 source 行静默丢弃。
     *
     * @param string $routeName 当前命中路由名（如 admin.setting）
     * @param array $context 页面视图数据（config_list / lang_list 等）
     * @return array|null
     */
    public static function resolve($routeName, array $context)
    {
        $routeName = (string) $routeName;
        if ($routeName === '') {
            return null;
        }

        foreach (self::groups() as $key => $group) {
            if (!self::matches($routeName, (array) $group['match'])) {
                continue;
            }

            return self::buildGroup($key, $group, $context, $routeName);
        }

        return null;
    }

    /**
     * 组匹配：match 写路由别名数组，展开后按段边界通配判断。
     *
     * @param string $routeName
     * @param array $aliases
     * @return bool
     */
    private static function matches($routeName, array $aliases)
    {
        foreach (AdminNavResolver::expandAliases($aliases) as $pattern) {
            if (AdminNavResolver::routeMatches($routeName, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 组装单个声明组为模板可直接消费的结构。
     *
     * @param string $key
     * @param array $group
     * @param array $context
     * @param string $routeName
     * @return array
     */
    private static function buildGroup($key, array $group, array $context, $routeName)
    {
        $home = isset($group['home']) ? (string) $group['home'] : '';
        $isHome = ($home === '' || $routeName === $home);

        $items = array();
        foreach ((array) $group['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (isset($item['source'])) {
                foreach (self::buildSourceItems((array) $item['source'], $context, $home, $isHome) as $built) {
                    $items[] = $built;
                }
                continue;
            }

            $built = self::buildStaticItem($item, $context, $routeName, $home, $isHome);
            if ($built !== null) {
                $items[] = $built;
            }
        }

        return array(
            'key' => (string) $key,
            'items' => $items,
        );
    }

    /**
     * 组装静态项：锚点触发器或普通路由链接。
     *
     * @param array $item
     * @param array $context
     * @param string $routeName
     * @param string $home
     * @param bool $isHome
     * @return array|null
     */
    private static function buildStaticItem(array $item, array $context, $routeName, $home, $isHome)
    {
        $when = isset($item['when']) ? (array) $item['when'] : array();
        if (!empty($when) && !AdminMenuRegistry::passWhen($when)) {
            return null;
        }

        if (!empty($item['when_var']) && empty($context[$item['when_var']])) {
            return null;
        }

        $isAnchor = !empty($item['anchor']);
        if ($isAnchor) {
            $anchor = ltrim((string) $item['anchor'], '#');
            // 关联页面复用本组时，页面内锚点改为跳回主页面对应分组
            if (!$isHome) {
                $isAnchor = false;
                $url = Util::absolutizeEntryUrl(route($home)) . '#' . $anchor;
            } else {
                $url = '#' . $anchor;
            }
        } elseif (!empty($item['link'])) {
            $params = isset($item['params']) ? (array) $item['params'] : array();
            $url = Util::absolutizeEntryUrl(route($item['link'], $params));
        } else {
            return null;
        }

        if (!$isAnchor && !empty($item['hash'])) {
            $url .= '#' . ltrim((string) $item['hash'], '#');
        }

        $key = isset($item['key']) ? (string) $item['key'] : '';
        if ($key === '') {
            $key = $isAnchor ? (string) $item['anchor'] : (string) $item['link'];
        }

        // 普通链接项与当前路由同名时高亮（如设置体系内的扩展字段页）；active 可补充同一入口的其它别名
        $isActive = !$isAnchor && !empty($item['link']) && (string) $item['link'] === $routeName;
        if (!$isActive && !$isAnchor && !empty($item['active'])) {
            $isActive = self::matches($routeName, (array) $item['active']);
        }

        return array(
            'key' => $key,
            'name' => lang(isset($item['name']) ? $item['name'] : $key),
            'url' => $url,
            'is_anchor' => $isAnchor,
            'is_active' => $isActive,
        );
    }

    /**
     * 动态项展开：按 source.format 从上下文变量映射为 tab 项。
     *
     * @param array $source
     * @param array $context
     * @param string $home
     * @param bool $isHome
     * @return array
     */
    private static function buildSourceItems(array $source, array $context, $home, $isHome)
    {
        $var = isset($source['var']) ? (string) $source['var'] : '';
        if ($var === '') {
            return array();
        }

        $rows = isset($context[$var]) ? (array) $context[$var] : array();
        $format = isset($source['format']) ? (string) $source['format'] : 'anchor';
        $activeVar = isset($source['active_var']) ? (string) $source['active_var'] : '';

        $items = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ($format === 'anchor') {
                $built = self::sourceAnchorItem($row, $home, $isHome);
            } elseif ($format === 'setting_link') {
                $built = self::sourceSettingLinkItem($row);
            } elseif ($format === 'lang') {
                $built = self::sourceLangItem($row, $activeVar);
            } else {
                $built = null;
            }

            if ($built !== null) {
                $items[] = $built;
            }
        }

        return $items;
    }

    /**
     * config 分组 → 页面内锚点项（setting 页）；关联页复用时输出主页锚点链接。
     *
     * @param array $row
     * @param string $home
     * @param bool $isHome
     * @return array|null
     */
    private static function sourceAnchorItem(array $row, $home, $isHome)
    {
        $name = isset($row['name']) ? (string) $row['name'] : '';
        if ($name === '') {
            return null;
        }

        $isAnchor = $isHome;
        $url = $isHome
            ? '#' . $name
            : Util::absolutizeEntryUrl(route($home)) . '#' . $name;

        return array(
            'key' => $name,
            'name' => isset($row['lang']) ? (string) $row['lang'] : $name,
            'url' => $url,
            'is_anchor' => $isAnchor,
            'is_active' => false,
        );
    }

    /**
     * config 分组 → 主设置页分组锚点链接（language system 页）。
     *
     * @param array $row
     * @return array|null
     */
    private static function sourceSettingLinkItem(array $row)
    {
        $name = isset($row['name']) ? (string) $row['name'] : '';
        if ($name === '') {
            return null;
        }

        return array(
            'key' => $name,
            'name' => isset($row['lang']) ? (string) $row['lang'] : $name,
            'url' => Util::absolutizeEntryUrl(route('admin.setting')) . '#' . $name,
            'is_anchor' => false,
            'is_active' => false,
        );
    }

    /**
     * 语言列表 → 语言设置链接项，active_var（cur）为真时高亮当前语言。
     *
     * @param array $row
     * @param string $activeVar
     * @return array|null
     */
    private static function sourceLangItem(array $row, $activeVar)
    {
        $pack = isset($row['language_pack']) ? (string) $row['language_pack'] : '';
        if ($pack === '') {
            return null;
        }

        $name = isset($row['name']) ? (string) $row['name'] : $pack;

        return array(
            'key' => $pack,
            'name' => $name . lang('language_setting'),
            'url' => Util::absolutizeEntryUrl(route('admin.language.system', array('language_pack' => $pack))),
            'is_anchor' => false,
            'is_active' => ($activeVar !== '' && !empty($row[$activeVar])),
        );
    }
}
