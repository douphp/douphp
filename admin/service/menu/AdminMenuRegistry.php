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
 * 可选模块的子菜单族不在此硬编码：各模块包自带 admin/submenu/<module>.php 声明文件
 * （纯 return 族数组，族名 = 文件名，随安装就位 / 卸载删除），{@see self::submenus()}
 * 启动装配时 glob 合并——未安装模块的族自然不存在。侧栏节点只由本文件的
 * sidebarNodes / passWhen 与 config 模块清单决定，不再存在跨域归属声明文件。
 *
 * 声明里出现的字符串一律是「路由别名」（路由名去掉 admin. 前缀，如 book.rule_slot），
 * 不手写 admin. 前缀与 .* 通配；匹配模式由 {@see AdminNavResolver::expandAliases()}
 * 在解析时生成（link 字段是路由名，仍写全名）。
 *
 * 子菜单族的 item 支持三种写法（详见 {@see AdminNavResolver::normalizeItem()}）：
 *   - 别名串（首选）：'user' / 'user.contact' / 'user.level_log'，key / name / link /
 *     match / renders 全部按约定推导；
 *   - 带 'alias' 的数组：先按别名推导，再用同层显式键覆盖 key / name / link / params /
 *     when / exclude / renders；不写 match（跨域归属改用族级 'also'）；
 *   - 全字段数组（无 alias）：不推导，用于项名与别名不同形、或指向它模块路由的纯链接行。
 *
 * 族级 'also' 挂载表：array('子域别名' => '归属项别名')，把无独立入口的子域折进目标项的
 * 匹配模式且不渲染菜单行（如 book 族 array('book.rule_slot' => 'book.rule')）。
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
 *   - match     匹配模式数组（仅全字段数组书写，元素为路由别名；别名形式由推导给出）；
 *               省略或空数组表示永不默认激活（纯链接行，仅供页面级覆盖）；
 *   - when      可见条件（见 passWhen）；不满足则该 item 不参与匹配与渲染；
 *   - exclude   排除模式数组（命中即本 item 不匹配），元素写路由别名。仅在「被排除的域可能
 *               不属于本族任何项」时才需要：同族无条件兄弟项的模式必长于本项、specificity
 *               自然胜出，写了也不改变裁决；而同族兄弟项带 when 时（如小程序族的 list 项排除
 *               nav / show，小程序签形态下两项被丢弃），exclude 才真正拦下归兜底项的高亮；
 *   - renders   本 item 命中时实际渲染的子菜单族（缺省为所在族；别名首段为其它已存在
 *               族名时自动指向该族，如会员族的 'distribution' 入口命中时渲染分销族）；
 *               仅在自动推导不符时才显式写。
 *
 * item 可见性：link 对应的路由名未在路由清单注册（模块未安装）时该项不参与匹配与渲染，
 * 因此跨模块入口只需在声明文件写全，随装卸自动增减。
 *
 * sidebar 节点字段：
 *   - id         侧栏 li 的 data-id（JS 滚动定位锚点）；
 *   - name       语言键；
 *   - icon       MenuIconMap 图标 slug（由 WorkspaceBuilder 解析为 class）；
 *   - link/params 指向路由；
 *   - match/exclude/when 同 item；节点的 match 只写「附加兼管域」（别名数组），
 *     主域由 link 推导（{@see AdminNavResolver::normalizeNode()}）；
 *   - permission 所需基础菜单权限键（workspace.menu_permission.*，由 Builder 过滤）；
 *   - badge      更新角标键（$unum.*，由 Builder 取整数值）；
 *   - children   二级子项（栏目模块「全部 / 分类」），含 category_match 判定分类高亮。
 */
class AdminMenuRegistry
{
    /** @var array|null 装配缓存（请求级）：核心族 + 已安装模块声明文件合并结果 */
    private static $submenus = null;

    /**
     * 子菜单族装配：核心族 + 模块声明文件（admin/submenu/<module>.php）。
     *
     * 模块声明文件返回族定义数组（title / icon / items），族名取文件名；
     * 目录不存在或无文件（模块未安装）时对应族自然缺失。平局 specificity
     * 取先声明者，跨族接管场景由 item.renders 显式声明，不依赖装配顺序。
     *
     * @return array<string, array{title: string, icon: string, items: array}>
     */
    public static function submenus()
    {
        if (self::$submenus !== null) {
            return self::$submenus;
        }

        $families = array(
            'manager' => array(
                'title' => 'manager',
                'icon' => 'bi-person-circle',
                'items' => array(
                    'manager',
                    'manager.log',
                ),
            ),
            // 小程序族只有三处需要数组形式：工作台项文案与别名不同形（需 name）且要排除 nav / show
            // 域（那两项带 when，形态切换后会落空），nav / show 项要携带 when 条件；其余均为纯别名。
            // data 与 module 是指向他模块路由的纯链接行，无别名即不参与匹配
            'miniprogram' => array(
                'title' => 'miniprogram',
                'icon' => 'bi-wechat',
                'items' => array(
                    array(
                        'alias' => 'miniprogram', 'name' => 'miniprogram_list',
                        'exclude' => array('miniprogram.nav', 'miniprogram.show'),
                    ),
                    array('alias' => 'miniprogram.nav', 'when' => array('sign_not' => 'miniprogram')),
                    array('alias' => 'miniprogram.show', 'when' => array('sign_not' => 'miniprogram')),
                    array(
                        'key' => 'data', 'name' => 'miniprogram_data', 'link' => 'admin.data', 'params' => array('group' => 'miniprogram'),
                        'when' => array('sign_not' => 'miniprogram'),
                    ),
                    'miniprogram.system',
                    'miniprogram.release',
                    array(
                        'key' => 'module', 'name' => 'module', 'link' => 'admin.module',
                        'when' => array('not_close_douphp_plus' => true),
                    ),
                ),
            ),
            'site_home' => array(
                'title' => 'menu_site_home_other',
                'icon' => 'bi bi-pie-chart',
                'items' => array(
                    'site_home',
                    'show',
                    'data',
                    // 横幅数据组与上一项同指 admin.data，靠 params 区分；无独立路由域，
                    // 故不写别名（不参与匹配，仅供跳转与页面级 subitem 覆盖）
                    array(
                        'key' => 'data_banner', 'link' => 'admin.data', 'params' => array('group' => 'banner'),
                    ),
                ),
            ),
        );

        // 已安装模块的族声明文件（随模块包安装 / 卸载生命周期，未安装则文件不存在）
        // 后台目录名取自 ADMIN_DIR（可被 storage/state/admin_dir.php 改写），须用 ADMIN_PATH 拼接，
        // 不能硬编码 'admin'，否则改名后台（如 ..c）时 glob 落空、模块族（user/ai 等）全部丢失。
        $submenuDir = ADMIN_PATH . 'submenu/';
        foreach ((array) glob($submenuDir . '*.php') as $file) {
            $declared = include $file;
            if (is_array($declared) && isset($declared['title'], $declared['items'])) {
                $families[basename($file, '.php')] = $declared;
            }
        }

        self::$submenus = $families;

        return $families;
    }

    /**
     * 侧栏节点定义（按渲染节分组）。
     *
     * 节序即渲染序：top（首页）→ main（基础三项）→ item（全能内容）→ bot（底部族）。
     * 节点匹配域：link 推导主域 + 显式 'match' 附加兼管域（见 AdminNavResolver::normalizeNode）。
     * 栏目 / 单页 / 页面树 / 商品分类为动态节点，由 WorkspaceBuilder 生成，不进本表。
     *
     * @return array<string, array<string, array>>
     */
    public static function sidebarNodes()
    {
        return array(
            'top' => array(
                'home' => array(
                    'id' => 'home', 'name' => 'menu_home', 'icon' => 'home',
                    'link' => 'admin.index', 'badge' => 'system',
                ),
            ),
            'main' => array(
                'setting' => array(
                    'id' => 'setting', 'name' => 'setting', 'icon' => 'setting',
                    'link' => 'admin.setting', 'permission' => 'setting',
                ),
                // 小程序签名下「导航」节点改指小程序导航（与 bot 的 miniprogram 节点互斥高亮）
                'miniprogram_nav' => array(
                    'id' => 'miniprogram_nav', 'name' => 'nav', 'icon' => 'nav',
                    'link' => 'admin.miniprogram.nav',
                    'permission' => 'nav', 'when' => array('sign' => 'miniprogram'),
                ),
                'nav' => array(
                    'id' => 'nav', 'name' => 'nav', 'icon' => 'nav',
                    'link' => 'admin.nav',
                    'permission' => 'nav', 'when' => array('sign_not' => 'miniprogram'),
                ),
                'page' => array(
                    'id' => 'page', 'name' => 'menu_page', 'icon' => 'page',
                    'link' => 'admin.page', 'permission' => 'page',
                ),
            ),
            'item' => array(
                'item_category' => array(
                    'id' => 'item_category', 'name' => 'item_category', 'icon' => 'article_cat',
                    'link' => 'admin.item.category',
                    'when' => array('feature' => 'item'),
                ),
                'item' => array(
                    'id' => 'item', 'name' => 'item', 'icon' => 'article',
                    'link' => 'admin.item',
                    'when' => array('feature' => 'item'), 'omit_when' => 'item_menu',
                ),
            ),
            'bot' => array(
                // 本站首页 / 展示 / 数据三个域共用一个节点：主域由 link 推导，
                // 其余两个写在此处（路由别名）
                'site_home' => array(
                    'id' => 'site_home', 'name' => 'site_home_other', 'icon' => 'show',
                    'link' => 'admin.site_home',
                    'match' => array('show', 'data'),
                    'permission' => 'site_home',
                ),
                'backup' => array(
                    'id' => 'backup', 'name' => 'backup', 'icon' => 'backup',
                    'link' => 'admin.backup', 'permission' => 'backup',
                ),
                'miniprogram' => array(
                    'id' => 'miniprogram', 'name' => 'miniprogram', 'icon' => 'miniprogram',
                    'link' => 'admin.miniprogram',
                    'permission' => 'miniprogram', 'badge' => 'miniprogram', 'when' => array('not_close_miniprogram' => true),
                ),
                'theme' => array(
                    'id' => 'theme', 'name' => 'theme', 'icon' => 'theme',
                    'link' => 'admin.theme',
                    'permission' => 'theme', 'badge' => 'theme',
                    'when' => array('not_pure_mode' => true, 'sign_not' => 'miniprogram'),
                ),
                'manager' => array(
                    'id' => 'manager', 'name' => 'manager', 'icon' => 'manager',
                    'link' => 'admin.manager', 'permission' => 'manager',
                ),
            ),
            // 顶栏（header）消费：模块入口高亮，不渲染为侧栏 li
            'header' => array(
                'module' => array(
                    'id' => 'module', 'name' => 'top_module', 'icon' => 'module',
                    'link' => 'admin.module',
                ),
            ),
        );
    }

    /**
     * 条件求值：注册表声明式条件 → 当前环境布尔判定。
     *
     * 支持键：sign / sign_not（SYSTEM_SIGN 相等与不等）、feature（features.<x> 开启）、
     * not_pure_mode（非纯模式——与视图变量 $pure_mode 同语义：仅当授权通过且
     * site.pure_mode 为非空真值时纯净模式才生效）、not_close_douphp_plus /
     * not_close_miniprogram（对应关闭开关为假）。
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
            if ($key === 'not_pure_mode' && $value
                && Config::get('app.licensed', false)
                && !empty(Config::get('site.pure_mode', ''))) {
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
