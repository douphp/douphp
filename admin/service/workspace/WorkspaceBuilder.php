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

namespace Dou\Admin\Service\Workspace;

use Dou\Admin\Service\Menu\AdminMenuService;
use Dou\Admin\Service\Preinstall\PreinstallService;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\MenuIconMap;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台工作台侧栏 ViewModel：栏目/单页/页面/商品分类菜单与权限映射、后台主题自定义开关。
 *
 * 返回值用于模板顶层 {$workspace.*}（见 admin/view/inc/header.tpl、sidebar.tpl、toolbar.tpl）。
 * 本类只组装数据数组，禁止直接 touch Smarty；由调用方显式 assign。
 *
 * 活跃态协议（与 $nav 契约配合）：
 *   - 栏目 / 单页节点（menu_column / menu_single）：不携带活跃态，侧栏按模块短名索引
 *     中央解析结果 $nav.sidebar[<模块名>]，保证控制器层 override 生效；
 *   - 页面树（menu_simple）：记录驱动动态节点，路由阶段无法解析，
 *     由本类在枚举的同一次遍历中就地计算 is_active（$nav.sidebar 不含这些逐行 id）；
 *   - 预置模块行（menu_preinstall）：同路由名不同 slug 的路由参数无法被 $nav.sidebar 区分，
 *     同样由本类按当前 slug 就地计算 is_active；槽位预置项（menu_preinstall_setting /
 *     menu_preinstall_fixed，如 language / plugin）与通用列表互斥，只在对应菜单位置渲染。
 */
class WorkspaceBuilder extends BaseService
{
    /** @var AdminMenuService */
    private $menuService;

    /** @var PreinstallService */
    private $preinstallService;

    /** @var array 进程内构建结果缓存（同请求内 init 与 workspace 中间件各调一次 build，去重） */
    private static $buildMemo = array();

    /**
     * @param AdminMenuService $menuService 框架基础菜单元数据
     * @param PreinstallService $preinstallService 预置模块标识读取
     */
    public function __construct(AdminMenuService $menuService, PreinstallService $preinstallService)
    {
        $this->menuService = $menuService;
        $this->preinstallService = $preinstallService;
    }

    /**
     * 组装后台工作台 Smarty 变量数组。
     *
     * 由调用方（middleware / init）从 Request 取好 routeModule 后显式传入；
     * 本服务自身不读 Request。
     *
     * @param string $currentModule 当前路由 module（dispatch 之前可传空串）
     * @param string $pageId 当前 URL 选中的单页 id（路由参数，仅 module=page 时消费，用于页面树高亮）
     * @param string $preinstallSlug 当前 URL 选中的预置模块 slug（路由参数，仅 preinstall 详情页消费，用于预置项高亮）
     * @return array
     */
    public function build($currentModule = '', $pageId = '', $preinstallSlug = '')
    {
        $memoKey = $currentModule . "\0" . $pageId . "\0" . $preinstallSlug;
        if (isset(self::$buildMemo[$memoKey])) {
            return self::$buildMemo[$memoKey];
        }

        $menuList = $this->buildModuleMenus();

        $workspace = array();
        $workspace['menu_column'] = isset($menuList['column_module']) ? $menuList['column_module'] : array();
        $workspace['menu_single'] = isset($menuList['single_module']) ? $menuList['single_module'] : array();
        // 页面树高亮仅认单页编辑路由（其余模块的同名 id 参数不参与）
        $workspace['menu_simple'] = $this->buildPageMenuTree(0, $currentModule === 'page' ? (string) $pageId : '');
        $workspace['admin_theme_custom'] = array_merge(
            array(
                'header' => false,
                'sidebar' => false,
                'toolbar' => false,
                'index' => false,
            ),
            $this->adminThemeFlags()
        );
        $workspace['menu_permission'] = $this->buildBasicMenuPermission();
        // 插件节点已移出单页菜单循环，固定渲染在侧栏底部（manager 上方）：
        // 权限口径与单页模块一致（defined 管理员需 action_list 勾选 plugin）
        $workspace['menu_permission']['plugin'] = !empty($menuList['fixed_module']['plugin']);
        // 预置项高亮仅认预置详情路由（plugin 等模块也有 slug 参数，不参与高亮）
        $activePreinstallSlug = $currentModule === 'preinstall' ? (string) $preinstallSlug : '';
        $workspace['menu_preinstall'] = $this->buildPreinstallMenu($activePreinstallSlug);
        // 槽位预置项：language 在设置子菜单、plugin 在侧栏底部固定位置，均不进入通用列表
        $workspace['menu_preinstall_setting'] = $this->buildSettingPreinstall($activePreinstallSlug);
        $workspace['menu_preinstall_fixed'] = $this->buildFixedPreinstall($activePreinstallSlug);
        $workspace['menu_icon_map'] = $this->buildMenuIconMapForView();

        return self::$buildMemo[$memoKey] = $workspace;
    }

    /**
     * 栏目模块 / 单页模块侧栏菜单。
     *
     * @return array
     */
    private function buildModuleMenus()
    {
        $admin = auth('admin')->user();
        if (!is_array($admin)) {
            $admin = array();
        }

        $adminType = isset($admin['type']) ? $admin['type'] : '';
        $menuList = array();
        $themeSupportModule = array();
        $actionAccess = false;

        if ($adminType != 'defined') {
            $actionAccess = true;
        }

        $themeSupportText = DB::table('parameter')->where('name', 'theme_support_module')->value('value');
        if ($themeSupportText) {
            $themeSupportModule = explode(',', $themeSupportText);
        }

        foreach ((array) Config::get('module.column_module') as $value) {
            if ($adminType == 'defined') {
                $actionList = isset($admin['action_list']) ? explode(',', $admin['action_list']) : array();
                $actionAccess = in_array($value, $actionList);
                $actionAccessCategory = in_array($value . '_category', $actionList);
            } else {
                $actionAccess = true;
                $actionAccessCategory = true;
            }

            if (!in_array($value, (array) Config::get('module.no_show_menu')) && ($actionAccess || $actionAccessCategory) && (!$themeSupportModule || in_array($value, $themeSupportModule))) {
                $menuList['column_module'][] = array(
                    'name_category' => $value . '_category',
                    'lang_category' => $actionAccessCategory ? lang($value . '_category') : '',
                    'name' => $value,
                    'lang' => $actionAccess ? lang($value) : '',
                    'lang_all' => lang($value . '_all'),
                    'lang_top_add' => lang('top_add_' . $value),
                    'url' => route('admin.' . $value),
                    'url_category' => route('admin.' . $value . '.category'),
                    'url_create' => route('admin.' . $value . '.create'),
                    'icon' => MenuIconMap::resolve($value),
                    'icon_category' => MenuIconMap::resolve($value . '-cat'),
                );
            }
        }

        foreach ((array) Config::get('module.single_module') as $value) {
            if ($adminType == 'defined') {
                $actionList = isset($admin['action_list']) ? explode(',', $admin['action_list']) : array();
                $actionAccess = in_array($value, $actionList);
            }

            if (!in_array($value, (array) Config::get('module.no_show_menu')) && !in_array($value, (array) Config::get('system.admin_hidden_single', array()), true) && $actionAccess && (!$themeSupportModule || in_array($value, $themeSupportModule))) {
                // 插件固定挂到侧栏底部（manager 上方），不进入单页菜单循环
                if ($value === 'plugin') {
                    $menuList['fixed_module']['plugin'] = true;
                    continue;
                }
                $menuList['single_module'][] = array(
                    'name' => $value,
                    'lang' => lang($value),
                    'url' => route('admin.' . $value),
                    'icon' => MenuIconMap::resolve($value),
                    'badge' => $this->singleModuleBadge($value),
                );
            }
        }

        return $menuList;
    }

    /**
     * 单模块侧栏角标：目前只有评论需要「管理员未读」计数，其余模块返回 0（不渲染）。
     *
     * 后台每个页面都会走这里，因此只认 method_exists 的探测结果：
     * comment 模块版本较旧（类已在磁盘、方法还没下发）时角标静默归零，
     * 不允许装饰性数据把整个后台外壳拖垮。
     *
     * @param string $module
     * @return int
     */
    private function singleModuleBadge($module)
    {
        if ($module !== 'comment' || !Config::get('features.comment', false)) {
            return 0;
        }

        $comment = Module::make('comment');
        if ($comment === null || !method_exists($comment, 'unreadCount')) {
            return 0;
        }

        return (int) $comment->unreadCount();
    }

    /**
     * 页面树形菜单。
     *
     * page 表一次性取出并按 parent_id 分组，避免逐层递归重复查库（原实现每层一次全表查询、并对每个
     * 节点再全表扫描判断是否有子级，整体为 N+1 + O(n²)）。
     *
     * @param int $parentId
     * @param string $currentId 当前编辑的单页 id（空串=无高亮）
     * @return array
     */
    private function buildPageMenuTree($parentId = 0, $currentId = '')
    {
        $data = DB::table('page')->field('id, slug, parent_id, name')->order('id ASC')->select();

        $grouped = array();
        foreach ((array) $data as $value) {
            $grouped[(int) $value['parent_id']][] = $value;
        }

        return $this->buildPageMenuNodes($grouped, (int) $parentId, (string) $currentId);
    }

    /**
     * 依据已按 parent_id 分组的行数据递归装配页面树节点。
     *
     * @param array $grouped parent_id => 行列表
     * @param int $parentId
     * @param string $currentId
     * @return array
     */
    private function buildPageMenuNodes(array $grouped, $parentId, $currentId)
    {
        $menuPage = array();
        if (empty($grouped[$parentId])) {
            return $menuPage;
        }

        foreach ($grouped[$parentId] as $value) {
            $isActive = $currentId !== '' && (string) $value['id'] === (string) $currentId;
            // is_active 为 $nav 契约字段；cur 为迁移期旧字段（模板切换后移除）
            $value['cur'] = $isActive;
            $value['is_active'] = $isActive;
            $value['icon'] = MenuIconMap::resolve($parentId > 0 ? 'menu-page' : '_default');

            $children = $this->buildPageMenuNodes($grouped, (int) $value['id'], $currentId);
            if (!empty($children)) {
                $value['child'] = $children;
            }

            $menuPage[] = $value;
        }

        return $menuPage;
    }

    /**
     * 框架基础菜单键权限映射。
     *
     * @return array
     */
    private function buildBasicMenuPermission()
    {
        $admin = auth('admin')->user();
        if (!is_array($admin)) {
            $admin = array();
        }

        $adminType = isset($admin['type']) ? $admin['type'] : '';
        $basicMenu = $this->menuService->basicMenu();
        $menuPermission = array();
        foreach ((array) $basicMenu as $menu) {
            $menuPermission[$menu] = false;
        }

        if ($adminType == 'defined') {
            $actionList = isset($admin['action_list']) ? explode(',', $admin['action_list']) : array();

            foreach ((array) $basicMenu as $menu) {
                $menuPermission[$menu] = in_array($menu, $actionList) ? true : false;
            }
        } else {
            foreach ((array) $basicMenu as $menu) {
                $menuPermission[$menu] = true;
            }
        }

        return $menuPermission;
    }

    /**
     * 预置模块侧栏菜单（menu_preinstall）。
     *
     * 数据源：storage/install/preinstall/ 标识文件 ∖ 已安装模块（config/module.php 口径）
     * ∖ header 槽位（如 language），每模块一条（与已安装单页模块一对一）；is_active 由
     * PreinstallService 按当前 slug 就地计算（同路由名不同 slug 参数，$nav.sidebar 无法区分）。
     *
     * 权限：defined 子管理员需 action_list 勾选 'preinstall'，未勾选返回空数组
     * （路由侧由 AdminGate 同步拦截）。
     *
     * @param string $activeSlug 当前详情页 slug（空串=无高亮）
     * @return array
     */
    private function buildPreinstallMenu($activeSlug)
    {
        if (!$this->preinstallAllowed()) {
            return array();
        }

        return $this->preinstallService->promotions($activeSlug);
    }

    /**
     * 设置子菜单槽位预置项（menu_preinstall_setting，slug => item）。
     *
     * 与侧栏预置项同源，仅装载 PreinstallService::SLOTS 的 setting 槽位（如 language）：
     * 这类模块未安装时由设置子菜单承载入口，安装后由真实菜单接管。
     *
     * @param string $activeSlug 当前详情页 slug（空串=无高亮）
     * @return array
     */
    private function buildSettingPreinstall($activeSlug)
    {
        if (!$this->preinstallAllowed()) {
            return array();
        }

        return $this->preinstallService->settingPromotions($activeSlug);
    }

    /**
     * 侧栏固定位置槽位预置项（menu_preinstall_fixed，slug => item）。
     *
     * 仅装载 PreinstallService::SLOTS 的 fixed 槽位（如 plugin）：预置入口与真实
     * 菜单项同位置（侧栏底部 manager 上方），安装后同一位置由真实菜单接管。
     *
     * @param string $activeSlug 当前详情页 slug（空串=无高亮）
     * @return array
     */
    private function buildFixedPreinstall($activeSlug)
    {
        if (!$this->preinstallAllowed()) {
            return array();
        }

        return $this->preinstallService->fixedPromotions($activeSlug);
    }

    /**
     * 预置模块入口权限：defined 子管理员需 action_list 勾选 'preinstall'。
     *
     * 与预置详情页路由侧 AdminGate 判定一致（preinstall 为独立可授项）。
     *
     * @return bool
     */
    private function preinstallAllowed()
    {
        $admin = auth('admin')->user();
        if (!is_array($admin)) {
            $admin = array();
        }

        if (!isset($admin['type']) || $admin['type'] !== 'defined') {
            return true;
        }

        $actionList = isset($admin['action_list']) ? explode(',', $admin['action_list']) : array();

        return in_array('preinstall', $actionList);
    }

    /**
     * 侧栏模板用图标映射：含连字符 slug 的 underscore 别名，避免模板引擎将 a-b 解析为减法。
     *
     * @return array<string, string>
     */
    private function buildMenuIconMapForView()
    {
        $map = MenuIconMap::all();
        foreach ($map as $slug => $biClass) {
            if (strpos($slug, '-') !== false) {
                $alias = str_replace('-', '_', $slug);
                if (!isset($map[$alias])) {
                    $map[$alias] = $biClass;
                }
            }
        }

        return $map;
    }

    /**
     * 后台主题自定义开关映射（config/system.php 中 system.admin_theme_custom）。
     *
     * @return array
     */
    private function adminThemeFlags()
    {
        $adminThemeCustom = array();
        foreach ((array) Config::get('system.admin_theme_custom', array()) as $name) {
            $adminThemeCustom[$name] = true;
        }

        return $adminThemeCustom;
    }
}
