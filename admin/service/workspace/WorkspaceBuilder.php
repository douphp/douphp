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
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\System\ModuleSettingReader;
use Dou\Core\Support\MenuIconMap;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台工作台侧栏 ViewModel：栏目/单页/页面/商品分类菜单与权限映射、后台主题自定义开关。
 *
 * 返回值用于模板顶层 {$workspace.*}（见 admin/view/inc/header.tpl、sidebar.tpl、toolbar.tpl）。
 * 本类只组装数据数组，禁止直接 touch Smarty；由调用方显式 assign。
 */
class WorkspaceBuilder extends BaseService
{
    /** @var ModuleSettingReader */
    private $moduleSettingReader;

    /** @var AdminMenuService */
    private $menuService;

    /**
     * @param ModuleSettingReader $moduleSettingReader 用于读取 config/module.php 中的 admin_theme_custom
     * @param AdminMenuService $menuService 框架基础菜单元数据
     */
    public function __construct(ModuleSettingReader $moduleSettingReader, AdminMenuService $menuService)
    {
        $this->moduleSettingReader = $moduleSettingReader;
        $this->menuService = $menuService;
    }

    /**
     * 组装后台工作台 Smarty 变量数组。
     *
     * 由调用方（middleware / init）从 Request 取好 routeModule 后显式传入；
     * 本服务自身不读 Request。
     *
     * @param string $currentModule 当前路由 module（dispatch 之前可传空串）
     * @param string $catId 当前 URL 选中的商品分类 category_id（查询参数，由调用方从 Request 取好传入，用于侧栏高亮）
     * @return array
     */
    public function build($currentModule = '', $catId = '')
    {
        $menuList = $this->buildModuleMenus();

        $workspace = array();
        $workspace['menu_column'] = isset($menuList['column_module']) ? $menuList['column_module'] : array();
        $workspace['menu_single'] = isset($menuList['single_module']) ? $menuList['single_module'] : array();
        $workspace['menu_simple'] = $this->buildPageMenuTree();
        $workspace['admin_theme_custom'] = array_merge(
            array(
                'header' => false,
                'menu' => false,
                'handle' => false,
                'index' => false,
            ),
            $this->adminThemeFlags()
        );
        $workspace['menu_item'] = $this->buildItemCategoryMenu((string) $currentModule, (string) $catId);
        $workspace['menu_permission'] = $this->buildBasicMenuPermission();
        $workspace['menu_icon_map'] = $this->buildMenuIconMapForView();

        return $workspace;
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

        $themeSupportText = DB::getValue('parameter', 'value', "name = 'theme_support_module'");
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
                $menuList['single_module'][] = array(
                    'name' => $value,
                    'lang' => lang($value),
                    'url' => route('admin.' . $value),
                    'icon' => MenuIconMap::resolve($value),
                );
            }
        }

        return $menuList;
    }

    /**
     * 页面树形菜单。
     *
     * @param int $parentId
     * @param string $currentId
     * @return array
     */
    private function buildPageMenuTree($parentId = 0, $currentId = '')
    {
        $menuPage = array();
        $data = DB::table('page')->field('id, slug, parent_id, name')->order('id ASC')->select();
        foreach ((array) $data as $value) {
            if ($value['parent_id'] == $parentId) {
                $value['cur'] = $value['id'] == $currentId ? true : false;
                $value['icon'] = MenuIconMap::resolve($parentId > 0 ? 'menu-page' : '_default');

                foreach ($data as $child) {
                    if ($child['parent_id'] == $value['id']) {
                        $value['child'] = $this->buildPageMenuTree($value['id'], $currentId);
                        break;
                    }
                }
                $menuPage[] = $value;
            }
        }

        return $menuPage;
    }

    /**
     * 商品分类工作台条目。
     *
     * @param string $currentModule 当前路由 module，由 build() 传入
     * @param string $catId 当前 URL 选中的商品分类 category_id，由 build() 传入（侧栏高亮判定）
     * @return array
     */
    private function buildItemCategoryMenu($currentModule, $catId = '')
    {
        $categoryList = array();

        if (Config::get('features.item', false)) {
            $rows = DB::table('item_category')
                ->where('parent_id', 0)
                ->order('sort ASC, id ASC')
                ->select();
            foreach ((array) $rows as $row) {
                $row = language()->langBox($row, 'item_category', 'name');
                $rowCatId = isset($row['id']) ? $row['id'] : 0;
                $addTime = Util::toTimestamp(isset($row['created_at']) ? $row['created_at'] : null);

                $categoryList[] = array(
                    'category_id' => $rowCatId,
                    'name' => isset($row['name']) ? $row['name'] : '',
                    'icon' => attachment()->url(isset($row['icon']) ? $row['icon'] : ''),
                    'slug' => isset($row['slug']) ? $row['slug'] : '',
                    'cur' => $rowCatId == $catId && $currentModule == 'item' ? true : false,
                    'created_at' => $addTime !== null ? date('Y-m-d', $addTime) : '',
                    'url' => route('admin.item', array('category_id' => $rowCatId)),
                );
            }
        }

        return $categoryList;
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
     * 后台主题自定义开关映射（data/system.php 中 admin_theme_custom）。
     *
     * @return array
     */
    private function adminThemeFlags()
    {
        $adminThemeCustom = array();
        $readSystem = $this->moduleSettingReader->read();
        if (isset($readSystem['admin_theme_custom'])) {
            foreach ((array) $readSystem['admin_theme_custom'] as $name) {
                $adminThemeCustom[$name] = true;
            }
        }

        return $adminThemeCustom;
    }
}
