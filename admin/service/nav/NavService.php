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

namespace Dou\Admin\Service\Nav;

use Dou\Admin\Model\Nav\Nav;
use Dou\Admin\Model\Page\Page;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Module\ModuleModelResolver;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\UploadedFile;
use Dou\Core\Web\Routing\UrlGenerator;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 主导航（nav 表）
 *
 * insert/update/delete；校验失败抛出 DomainException；成功删除由 Controller 调 `respondDeleteResult($result)`（confirm_url 为空时走 302+flash，非空走二次确认）。
 */
class NavService extends BaseService
{
    /** @var ModuleModelResolver */
    private $resolver;

    /**
     * @param ModuleModelResolver $resolver 模块名 → 分类 Model FQCN（取分类层级缩进走 *Category::flat()）
     */
    public function __construct(ModuleModelResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * 列表页或父级下拉：某 type 下的树形展示数据。
     *
     * @param string $type middle|top|bottom
     * @param string $excludeCurrentId 编辑时排除自身 id，避免选自己为父级；列表页传空
     * @return array nav_list
     */
    public function buildNavListData($type, $excludeCurrentId = '')
    {
        $nav = array();
        // AR：casts(status data_lang) 已生效，icon URL/外链展开仍在 service 内逐条 enrich
        $rows = $this->collectNavRows();
        $this->buildNavTreeRowsFromRows($rows, $type, 0, 0, (string) $excludeCurrentId, $nav);

        return array(
            'nav_list' => $nav,
        );
    }

    /**
     * 新增页默认 assign（含 nav_info 占位，供模板回调 URL 使用）。
     *
     * @return array
     */
    public function buildNavDefaultData()
    {
        return array(
            'nav_info' => array(
                'id' => '',
                'name' => '',
                'url' => '',
                'type' => 'middle',
                'parent_id' => '',
                'sort' => '50',
                'icon' => '',
                'status' => '',
            ),
        );
    }

    /**
     * 编辑页数据：单条记录 + 展示用 url/icon。
     *
     * @param int $id
     * @return array|null
     */
    public function buildNavEditData($id)
    {
        $id = (int) $id;
        if ($id < 1) {
            return null;
        }

        $model = Nav::find($id);
        if (!$model) {
            return null;
        }
        $navInfo = $model->getAttributes();

        if ($navInfo['module'] === 'nav') {
            $navInfo['url'] = $navInfo['guide'];
        } else {
            list($navRoute, $navParams, $navOptions) = UrlGenerator::navStorageToUrlArgs($navInfo['module'], $navInfo['guide']);
            $navInfo['url'] = route($navRoute, $navParams, $navOptions);
        }

        if (Config::get('site.open_icon', '') === 'image') {
            $navInfo['file_number'] = Check::fileNumber($navInfo['icon']) ? $navInfo['icon'] : '';
            $navInfo['icon'] = Check::fileNumber($navInfo['icon']) ? attachment()->url($navInfo['icon']) : $navInfo['icon'];
        }

        return $navInfo;
    }

    /**
     * 父级下拉 HTML（nav_select 异步片段）。
     *
     * @param string $type
     * @param string $currentNavId 当前编辑/新增项 id，空或非法时 parent 比对为 null
     * @return string
     */
    public function buildNavParentSelectHtml($type, $currentNavId)
    {
        $type = $type !== '' ? trim($type) : 'middle';
        $rowId = (int) trim((string) $currentNavId);
        $parentId = $rowId > 0 ? Nav::getParentId($rowId) : null;

        $navList = array();
        $rows = $this->collectNavRows();
        $this->buildNavTreeRowsFromRows($rows, $type, 0, 0, $currentNavId, $navList);

        $select = '<select name="parent_id">';
        $select .= '<option value="0">' . lang('empty') . '</option>';
        foreach ((array) $navList as $value) {
            $select .= '<option value="' . (int) $value['id'] . '" ';
            $select .= ($value['id'] == $parentId) ? "selected='true'" : '';
            $select .= '>' . $value['mark'] . ' ';
            $select .= htmlspecialchars($value['name'], ENT_QUOTES) . '</option>';
        }
        $select .= '</select>';

        return $select;
    }

    /**
     * @param array $data NavFormRequest::validated()
     * @param string $openIcon image|text|其它
     * @return int 新增记录主键
     */
    public function insert(array $data, $openIcon)
    {
        $back = $this->navListUrl($data);

        $navMenuStr = isset($data['nav_menu']) ? trim((string) $data['nav_menu']) : '';
        $nav_menu = explode(',', $navMenuStr);
        $module = isset($nav_menu[0]) ? trim($nav_menu[0]) : '';
        $guide = $module === 'nav' ? trim(isset($data['guide']) ? (string) $data['guide'] : '') : (isset($nav_menu[1]) ? $nav_menu[1] : '');

        $iconText = '';
        if ($openIcon === 'text') {
            $iconText = isset($data['icon']) ? (string) $data['icon'] : '';
        }

        $row = array(
            'module' => $module,
            'name' => isset($data['name']) ? $data['name'] : '',
            'icon' => $iconText,
            'guide' => $guide,
            'parent_id' => isset($data['parent_id']) ? (int) $data['parent_id'] : 0,
            'type' => isset($data['type']) && $data['type'] !== '' ? $data['type'] : 'middle',
            'sort' => isset($data['sort']) ? (int) $data['sort'] : 0,
        );

        $model = Nav::create($row);
        $id = (int) $model->getKey();

        if ($openIcon === 'image') {
            $icon = attachment()->store('nav', $id, UploadedFile::fromGlobals('icon'), 'main', AttachmentUploadOptions::create()->withUploader('admin', (int) auth('admin')->id()));
            if ($icon !== '') {
                Nav::whereKey($id)->update(array('icon' => $icon));
            }
        }

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CREATE, 1, (string) $row['name']);

        return $id;
    }

    /**
     * @param array $data NavFormRequest::validated()
     * @param string $openIcon image|text|其它
     * @return void
     */
    public function update(array $data, $openIcon)
    {
        $back = $this->navListUrl($data);

        $id = isset($data['id']) ? (int) $data['id'] : 0;
        if ($id < 1) {
            throw new DomainException(lang('illegal'), $back);
        }

        $nav = Nav::find($id);
        if (!$nav) {
            throw new DomainException(lang('illegal'), $back);
        }

        $update_data = array(
            'name' => isset($data['name']) ? $data['name'] : '',
            'parent_id' => isset($data['parent_id']) ? (int) $data['parent_id'] : 0,
            'type' => isset($data['type']) && $data['type'] !== '' ? $data['type'] : 'middle',
            'status' => isset($data['status']) ? (int) $data['status'] : 0,
            'sort' => isset($data['sort']) ? (int) $data['sort'] : 0,
        );

        $navMenuStr = isset($data['nav_menu']) ? trim((string) $data['nav_menu']) : '';
        if ($navMenuStr !== '') {
            $nav_menu = explode(',', $navMenuStr);
            $update_data['module'] = isset($nav_menu[0]) ? $nav_menu[0] : '';
            $update_data['guide'] = isset($nav_menu[1]) ? $nav_menu[1] : '';
        } else {
            $update_data['guide'] = isset($data['guide']) ? trim((string) $data['guide']) : '';
        }

        if ($openIcon === 'image') {
            $newIcon = attachment()->store('nav', $id, UploadedFile::fromGlobals('icon'), 'main', AttachmentUploadOptions::create()->withUploader('admin', (int) auth('admin')->id()));
            if ($newIcon !== '') {
                $update_data['icon'] = $newIcon;
            }
        } elseif ($openIcon === 'text') {
            $update_data['icon'] = isset($data['icon']) ? (string) $data['icon'] : '';
        }

        $nav->fill($update_data, 'update')->save();
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $update_data['name']);
    }

    /**
     * 单条删除：二次确认或实际删除。
     *
     * @param int|string $id
     * @param array $post
     * @return array message、back_url、timeout、confirm_url
     * @throws DomainException 参数不合法 / 存在子项时抛出
     */
    public function delete($id, array $post)
    {
        $navModel = Nav::find((int) $id);
        if (!$navModel) {
            throw new DomainException(lang('illegal'), route('admin.nav'));
        }
        $nav_info = $navModel->getAttributes();

        $backWithType = route('admin.nav', array('type' => $nav_info['type']));

        if (Nav::hasChild((int) $id)) {
            $msg = preg_replace('/d%/Ums', $nav_info['name'], lang('nav_del_is_parent'));
            throw new DomainException($msg, $backWithType, '3');
        }

        if (isset($post['confirm'])) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $nav_info['name']);
            Nav::destroy((int) $id);

            return array(
                'message' => lang('del_succes'),
                'back_url' => $backWithType,
            );
        }

        $del_check = preg_replace('/d%/Ums', $nav_info['name'], lang('del_check'));
        return array(
            'message' => $del_check,
            'back_url' => $backWithType,
            'timeout' => '30',
            'confirm_url' => route('admin.nav.destroy', array('id' => (int) $id)),
        );
    }

    /**
     * 为导航选择器组装可选目标列表。
     *
     * 由 controller 显式传入当前模块名（取自路由），避免本服务自行触达 request()。
     *
     * @param string $module 参数 module
     * @param string $id 参数 id
     * @param string $currentModule 当前路由模块（'nav' / 'miniprogram'）
     * @return array
     */
    public function buildNavTargetList($module = '', $id = '', $currentModule = '')
    {
        $catalog = array();

        if ($currentModule == 'miniprogram') {
            $catalog[] = array(
                'name' => lang('miniprogram_nav_home'),
                'module' => 'index',
                'cur' => $module == 'index' ? true : false,
                'guide' => 'index',
                'mark' => '',
            );

            if (lang('product_all') !== '') {
                $catalog[] = array(
                    'name' => lang('product_all'),
                    'module' => 'search',
                    'cur' => ($module == 'search' && $id == 'list') ? true : false,
                    'guide' => 'list',
                    'mark' => '',
                );
            }

            $catalog[] = array(
                'name' => lang('page_about'),
                'module' => 'page',
                'cur' => ($module == 'page' && $id == 'about') ? true : false,
                'guide' => 'about',
                'mark' => '',
            );

            $catalog[] = array(
                'name' => lang('page_contact'),
                'module' => 'page',
                'cur' => ($module == 'page' && $id == 'contact') ? true : false,
                'guide' => 'contact',
                'mark' => '',
            );

            if (lang('order_cart') !== '') {
                $catalog[] = array(
                    'name' => lang('order_cart'),
                    'module' => 'order',
                    'cur' => ($module == 'order' && $id == 'cart') ? true : false,
                    'guide' => 'cart',
                    'mark' => '',
                );
            }
        }

        foreach ((array) Page::pageNolevel() as $row) {
            $pageMark = isset($row['mark']) ? $row['mark'] : '';
            $catalog[] = array(
                'name' => $row['name'],
                'module' => 'page',
                'guide' => $row['id'],
                'cur' => ($module == 'page' && $id == $row['id']) ? true : false,
                'mark' => $pageMark !== '' ? '-' . $pageMark : '',
            );
        }

        foreach ((array) Config::get('module.column_module') as $module_id) {
            if (!in_array($module_id, (array) Config::get('module.no_show_nav'))) {
                $navColKey = 'nav_' . $module_id;
                $catalog[] = array(
                    'name' => lang($navColKey) !== '' ? lang($navColKey) : $module_id,
                    'module' => $module_id . '_category',
                    'cur' => ($module == $module_id . '_category' && $id == 0) ? true : false,
                    'guide' => 0,
                    'mark' => '',
                );
                $catCls = $this->resolver->categoryModelClassFor($module_id);
                $cats = ($catCls !== null && method_exists($catCls, 'flat')) ? $catCls::flat() : array();
                foreach ($cats as $row) {
                    $catMark = isset($row['mark']) ? $row['mark'] : '';
                    $catalog[] = array(
                        'name' => $row['name'],
                        'module' => $module_id . '_category',
                        'guide' => $row['id'],
                        'cur' => ($module == $module_id . '_category' && $id == $row['id']) ? true : false,
                        'mark' => $catMark !== '' ? '-' . $catMark : '',
                    );
                }
            }
        }

        foreach ((array) Config::get('module.single_module') as $module_id) {
            if (!in_array($module_id, (array) Config::get('system.nav_hidden_single', array())) && !in_array($module_id, (array) Config::get('module.no_show_nav'))) {
                $singleNavKey = $module_id . '_nav';
                $singleName = lang($singleNavKey) !== '' ? lang($singleNavKey) : (lang($module_id) !== '' ? lang($module_id) : $module_id);
                $catalog[] = array(
                    'name' => $singleName,
                    'module' => $module_id,
                    'cur' => ($module == $module_id && $id == 0) ? true : false,
                    'guide' => 0,
                    'mark' => '',
                );
            }
        }

        return $catalog;
    }

    /**
     * 后台导航嵌套列表（单次读取 nav 表后在内存中递归；图标 URL、状态文案、外链展开）。
     *
     * @param array $data fetchAllOrdered() 结果
     * @param string $type
     * @param int $parent_id
     * @param int $level
     * @param string $current_id
     * @param array $nav
     * @param string $mark
     * @return void
     */
    private function buildNavTreeRowsFromRows(array $data, $type = 'middle', $parent_id = 0, $level = 0, $current_id = '', &$nav = array(), $mark = '-')
    {
        foreach ($data as $value) {
            if ($value['parent_id'] == $parent_id && $value['type'] == $type && (string) $value['id'] !== (string) $current_id) {
                if ($value['module'] != 'nav') {
                    list($navRoute, $navParams, $navOptions) = UrlGenerator::navStorageToUrlArgs($value['module'], $value['guide']);
                    $value['guide'] = route($navRoute, $navParams, $navOptions);
                }

                $value['icon'] = Config::get('site.open_icon', '') == 'image' ? attachment()->url($value['icon']) : $value['icon'];
                $value['mark'] = str_repeat($mark, $level);
                $nav[] = $value;
                $this->buildNavTreeRowsFromRows($data, $type, $value['id'], $level + 1, $current_id, $nav);
            }
        }
    }

    /**
     * 通过 AR 取全表，统一返回 toArray() 形态，便于 enrich/递归。
     *
     * @return array
     */
    private function collectNavRows()
    {
        $rows = array();
        foreach (Nav::listAllOrdered() as $m) {
            $rows[] = $m->toArray();
        }
        return $rows;
    }

    /**
     * @param array $data
     * @return string
     */
    private function navListUrl(array $data)
    {
        $type = isset($data['type']) && $data['type'] !== '' ? $data['type'] : 'middle';

        return Util::normalizeQueryString(route('admin.nav', array('type' => $type)));
    }
}
