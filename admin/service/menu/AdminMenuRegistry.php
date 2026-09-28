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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台菜单注册表：菜单即数据。
 *
 * 全后台的子菜单族与侧栏节点在此声明式定义——key / 标题语言键 / 图标 / 指向路由 /
 * 匹配规则（路由名通配）/ 可见条件。模板不再比较任何模块名字符串，活跃态由
 * {@see AdminNavResolver} 按当前命中路由统一计算为布尔值。
 *
 * 可选模块的子菜单族不在此硬编码：各模块包自带 admin/nav/<module>.php 声明文件
 * （纯 return 族数组，族名 = 文件名，随安装就位 / 卸载删除），{@see self::subMenus()}
 * 启动装配时 glob 合并——未安装模块的族自然不存在。sideNodes / passWhen 等平台
 * 装配语义与核心族（site_home / miniprogram / manager）常驻本文件。
 *
 * 匹配规则语法（由 AdminNavResolver::routeMatches 解释）：
 *   - `admin.user.*`    段边界通配：匹配 admin.user 及其任意层下级（点分号切段）；
 *   - `admin.user.log`  精确路由名；
 *   - 尾 `.*` 兼容基名：`admin.user.contact.*` 同时匹配 admin.user.contact 本身。
 *
 * item 字段：
 *   - key       本族内唯一标识（页面级覆盖按 key 激活）；
 *   - name      语言键（显示名，解析时经 lang() 求值）；
 *   - link      指向路由名（route($link)）；空则不可点击；
 *   - params    route() 附加参数（如 group=banner）；
 *   - match     路由名匹配模式数组；空数组表示永不默认激活（仅供页面级覆盖）；
 *   - when      可见条件（见 passWhen）；不满足则该 item 不参与匹配与渲染；
 *   - exclude   排除模式数组（命中即本 item 不匹配，用于「父项不吞掉归属他族的子域」，
 *               如 book 族排除 work 域）；
 *   - renders   本 item 命中时实际渲染的子菜单族（缺省为所在族；用于跨族入口，
 *               如会员族的「分销」入口命中时渲染分销族）；
 *   - source    动态项源（'user_center' 展开为 module.link_user_center 的入口列表）。
 *
 * side 节点字段：
 *   - id         侧栏 li 的 data-id（JS 滚动定位锚点）；
 *   - name       语言键；
 *   - icon       MenuIconMap 图标 slug（由 WorkspaceBuilder 解析为 class）；
 *   - link/params 指向路由；
 *   - match/exclude/when 同 item；
 *   - permission 所需基础菜单权限键（workspace.menu_permission.*，由 Builder 过滤）；
 *   - badge      更新角标键（$unum.*，由 Builder 取整数值）；
 *   - children   二级子项（栏目模块「全部 / 分类」），含 category_match 判定分类高亮。
 */
class AdminMenuRegistry
{
    /** @var array|null 装配缓存（请求级）：核心族 + 已安装模块声明文件合并结果 */
    private static $subMenus = null;

    /**
     * 子菜单族装配：核心族 + 模块声明文件（admin/nav/<module>.php）。
     *
     * 模块声明文件返回族定义数组（title / icon / items），族名取文件名；
     * 目录不存在或无文件（模块未安装）时对应族自然缺失。平局 specificity
     * 取先声明者，跨族接管场景由 item.renders 显式声明，不依赖装配顺序。
     *
     * @return array<string, array{title: string, icon: string, items: array}>
     */
    public static function subMenus()
    {
        if (self::$subMenus !== null) {
            return self::$subMenus;
        }

        $families = array(
            'manager' => array(
                'title' => 'manager',
                'icon' => 'bi-person-circle',
                'items' => array(
                    array('key' => 'manager', 'name' => 'manager', 'link' => 'admin.manager', 'match' => array('admin.manager.*')),
                    array('key' => 'log', 'name' => 'manager_log', 'link' => 'admin.manager.log', 'match' => array('admin.manager.log.*')),
                ),
            ),
            'miniprogram' => array(
                'title' => 'miniprogram',
                'icon' => 'bi-wechat',
                'items' => array(
                    array(
                        'key' => 'list', 'name' => 'miniprogram_list', 'link' => 'admin.miniprogram',
                        'match' => array('admin.miniprogram.*'),
                        'exclude' => array('admin.miniprogram.nav.*', 'admin.miniprogram.show.*'),
                    ),
                    array(
                        'key' => 'nav', 'name' => 'miniprogram_nav', 'link' => 'admin.miniprogram.nav',
                        'match' => array('admin.miniprogram.nav.*'), 'when' => array('sign_not' => 'miniprogram'),
                    ),
                    array(
                        'key' => 'show', 'name' => 'miniprogram_show', 'link' => 'admin.miniprogram.show',
                        'match' => array('admin.miniprogram.show.*'), 'when' => array('sign_not' => 'miniprogram'),
                    ),
                    array(
                        'key' => 'data', 'name' => 'miniprogram_data', 'link' => 'admin.data', 'params' => array('group' => 'miniprogram'),
                        'match' => array(), 'when' => array('sign_not' => 'miniprogram', 'feature' => 'data'),
                    ),
                    array('key' => 'system', 'name' => 'miniprogram_system', 'link' => 'admin.miniprogram.system', 'match' => array('admin.miniprogram.system.*')),
                    array('key' => 'release', 'name' => 'miniprogram_release', 'link' => 'admin.miniprogram.release', 'match' => array('admin.miniprogram.release.*')),
                    array(
                        'key' => 'module', 'name' => 'module', 'link' => 'admin.module',
                        'match' => array(), 'when' => array('not_close_douphp_plus' => true),
                    ),
                ),
            ),
            'site_home' => array(
                'title' => 'menu_site_home_other',
                'icon' => 'bi bi-pie-chart',
                'items' => array(
                    array('key' => 'site_home', 'name' => 'site_home', 'link' => 'admin.site_home', 'match' => array('admin.site_home.*')),
                    array('key' => 'show', 'name' => 'show', 'link' => 'admin.show', 'match' => array('admin.show.*')),
                    array(
                        'key' => 'data', 'name' => 'data', 'link' => 'admin.data',
                        'match' => array('admin.data.*'), 'when' => array('feature' => 'data'),
                    ),
                    array(
                        'key' => 'data_banner', 'name' => 'data_banner', 'link' => 'admin.data', 'params' => array('group' => 'banner'),
                        'match' => array(), 'when' => array('feature' => 'data'),
                    ),
                    array(
                        'key' => 'fragment', 'name' => 'fragment', 'link' => 'admin.fragment',
                        'match' => array('admin.fragment.*'), 'when' => array('feature' => 'fragment'),
                    ),
                    array(
                        'key' => 'box', 'name' => 'box', 'link' => 'admin.box',
                        'match' => array('admin.box.*'), 'when' => array('feature' => 'box'),
                    ),
                ),
            ),
        );

        // 已安装模块的族声明文件（随模块包安装 / 卸载生命周期，未安装则文件不存在）
        // 后台目录名取自 ADMIN_DIR（可被 storage/state/admin_dir.php 改写），须用 ADMIN_PATH 拼接，
        // 不能硬编码 'admin'，否则改名后台（如 ..c）时 glob 落空、模块族（user/ai 等）全部丢失。
        $navDir = ADMIN_PATH . 'nav/';
        foreach ((array) glob($navDir . '*.php') as $file) {
            $declared = include $file;
            if (is_array($declared) && isset($declared['title'], $declared['items'])) {
                $families[basename($file, '.php')] = $declared;
            }
        }

        self::$subMenus = $families;

        return $families;
    }

    /**
     * 侧栏节点定义（按渲染节分组）。
     *
     * 节序即渲染序：top（首页）→ main（基础三项）→ item（全能内容）→ bot（底部族）。
     * 栏目 / 单页 / 页面树 / 商品分类为动态节点，由 WorkspaceBuilder 生成，不进本表。
     *
     * @return array<string, array<string, array>>
     */
    public static function sideNodes()
    {
        return array(
            'top' => array(
                'home' => array(
                    'id' => 'home', 'name' => 'menu_home', 'icon' => 'home',
                    'link' => 'admin.index', 'match' => array('admin.index.*'), 'badge' => 'system',
                ),
            ),
            'main' => array(
                'setting' => array(
                    'id' => 'setting', 'name' => 'setting', 'icon' => 'setting',
                    'link' => 'admin.setting', 'match' => array('admin.setting.*'), 'permission' => 'setting',
                ),
                // 小程序签名下「导航」节点改指小程序导航（与 bot 的 miniprogram 节点互斥高亮）
                'miniprogram_nav' => array(
                    'id' => 'miniprogram_nav', 'name' => 'nav', 'icon' => 'nav',
                    'link' => 'admin.miniprogram.nav', 'match' => array('admin.miniprogram.nav.*'),
                    'permission' => 'nav', 'when' => array('sign' => 'miniprogram'),
                ),
                'nav' => array(
                    'id' => 'nav', 'name' => 'nav', 'icon' => 'nav',
                    'link' => 'admin.nav', 'match' => array('admin.nav.*'),
                    'permission' => 'nav', 'when' => array('sign_not' => 'miniprogram'),
                ),
                'page' => array(
                    'id' => 'page', 'name' => 'menu_page', 'icon' => 'page',
                    'link' => 'admin.page', 'match' => array('admin.page.*'), 'permission' => 'page',
                ),
            ),
            'item' => array(
                'item_category' => array(
                    'id' => 'item_category', 'name' => 'item_category', 'icon' => 'article_cat',
                    'link' => 'admin.item.category', 'match' => array('admin.item.category.*'),
                    'when' => array('feature' => 'item'),
                ),
                'item' => array(
                    'id' => 'item', 'name' => 'item', 'icon' => 'article',
                    'link' => 'admin.item', 'match' => array('admin.item.*'),
                    'when' => array('feature' => 'item'), 'omit_when' => 'item_menu',
                ),
            ),
            'bot' => array(
                'site_home' => array(
                    'id' => 'site_home', 'name' => 'site_home_other', 'icon' => 'show',
                    'link' => 'admin.site_home',
                    'match' => array('admin.site_home.*', 'admin.show.*', 'admin.data.*', 'admin.box.*', 'admin.fragment.*'),
                    'permission' => 'site_home_other',
                ),
                'backup' => array(
                    'id' => 'backup', 'name' => 'backup', 'icon' => 'backup',
                    'link' => 'admin.backup', 'match' => array('admin.backup.*'), 'permission' => 'backup',
                ),
                'miniprogram' => array(
                    'id' => 'miniprogram', 'name' => 'miniprogram', 'icon' => 'miniprogram',
                    'link' => 'admin.miniprogram', 'match' => array('admin.miniprogram.*'),
                    'permission' => 'miniprogram', 'badge' => 'miniprogram', 'when' => array('not_close_miniprogram' => true),
                ),
                'theme' => array(
                    'id' => 'theme', 'name' => 'theme', 'icon' => 'theme',
                    'link' => 'admin.theme', 'match' => array('admin.theme.*'),
                    'permission' => 'theme', 'badge' => 'theme',
                    'when' => array('not_pure_mode' => true, 'sign_not' => 'miniprogram'),
                ),
                'manager' => array(
                    'id' => 'manager', 'name' => 'manager', 'icon' => 'manager',
                    'link' => 'admin.manager', 'match' => array('admin.manager.*'), 'permission' => 'manager',
                ),
            ),
            // 顶栏（header）消费：模块入口高亮，不渲染为侧栏 li
            'header' => array(
                'module' => array(
                    'id' => 'module', 'name' => 'top_module', 'icon' => 'module',
                    'link' => 'admin.module', 'match' => array('admin.module.*'),
                ),
            ),
        );
    }

    /**
     * 会员中心入口模块 → 命中时渲染的子菜单族。
     *
     * 与 config/module.php 的 link_user_center 配合：入口项动态展开，
     * 缺省渲染会员族；分销入口指向分销族（其自身页面渲染分销子菜单）。
     *
     * @return array<string, string>
     */
    public static function userCenterFamilies()
    {
        return array(
            'distribution' => 'distribution',
        );
    }

    /**
     * 条件求值：注册表声明式条件 → 当前环境布尔判定。
     *
     * 支持键：sign / sign_not（SYSTEM_SIGN 相等与不等）、feature（features.<x> 开启）、
     * not_pure_mode（非纯模式）、not_close_douphp_plus / not_close_miniprogram（对应关闭开关为假）。
     *
     * @param array $when 条件表
     * @return bool
     */
    public static function passWhen(array $when)
    {
        foreach ($when as $key => $value) {
            if ($key === 'sign' && SYSTEM_SIGN !== $value) {
                return false;
            }
            if ($key === 'sign_not' && SYSTEM_SIGN === $value) {
                return false;
            }
            if ($key === 'feature' && !Config::get('features.' . $value, false)) {
                return false;
            }
            if ($key === 'not_pure_mode' && $value && Config::get('site.pure_mode', '') !== '') {
                return false;
            }
            if ($key === 'not_close_douphp_plus' && $value && Config::get('site.close_douphp_plus', false)) {
                return false;
            }
            if ($key === 'not_close_miniprogram' && $value && Config::get('site.close_miniprogram', false)) {
                return false;
            }
        }

        return true;
    }
}
