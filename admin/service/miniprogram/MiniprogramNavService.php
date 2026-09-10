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

namespace Dou\Admin\Service\Miniprogram;

use Dou\Admin\Model\Miniprogram\MiniprogramNav;
use Dou\Admin\Service\Nav\NavService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Check;
use Dou\Core\Support\Num;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台小程序导航业务
 */
class MiniprogramNavService extends BaseService
{
    /** @var NavService */
    private $navService;

    /** @var MiniprogramService */
    private $miniprogramService;

    /**
     * @param NavService $navService
     * @param MiniprogramService $miniprogramService
     */
    public function __construct(NavService $navService, MiniprogramService $miniprogramService)
    {
        $this->navService = $navService;
        $this->miniprogramService = $miniprogramService;
    }

    /**
     * 创建页前置校验（如 tabbar 数量上限）
     *
     * @param string $type
     * @return void
     * @throws DomainException
     */
    public function buildMiniprogramNavCreateData($type)
    {
        $this->miniprogramService->defineCodePath();
        if ($type === 'miniprogram_tabbar' && MiniprogramNav::countByType('miniprogram_tabbar') >= 5) {
            throw new DomainException(
                lang('miniprogram_nav_tabbar_outnumber'),
                route('admin.miniprogram.nav', array(), array('query' => array('type' => $type)))
            );
        }
    }

    /**
     * 新增导航表单：可选系统链接列表
     *
     * @return array
     */
    public function buildNavCatalogDefaultList()
    {
        $this->miniprogramService->defineCodePath();

        return $this->navService->buildNavTargetList('', '', 'miniprogram');
    }

    /**
     * @param string $type
     * @return array nav_list
     */
    public function buildMiniprogramNavListData($type)
    {
        $this->miniprogramService->defineCodePath();

        return array(
            'nav_list' => app(\Dou\Core\Service\Nav\MiniprogramNavigationBuilder::class)->build($type),
        );
    }

    /**
     * @param int    $id
     * @param string $typeFromRequest
     * @return array|null nav_info、type、catalog_list
     */
    public function buildMiniprogramNavEditData($id, $typeFromRequest)
    {
        $this->miniprogramService->defineCodePath();
        $id = (int) $id;
        if ($id < 1) {
            return null;
        }
        $nav_info = MiniprogramNav::findMiniprogramNav($id);
        if (!$nav_info) {
            return null;
        }
        $nav_info = $nav_info->getAttributes();
        $type = $typeFromRequest;
        if ($type === '' && !empty($nav_info['type'])) {
            $type = $nav_info['type'];
        }
        if ($type === '') {
            $type = 'miniprogram_top';
        }
        if (Check::number($nav_info['guide'])) {
            $param = '?' . (strpos($nav_info['module'], 'category') ? 'category_id' : 'id') . '=' . $nav_info['guide'];
            $nav_info['url'] = 'pages/' . $nav_info['module'] . '/' . $nav_info['module'] . ($nav_info['guide'] ? $param : '');
        } else {
            $nav_info['url'] = 'pages/' . $nav_info['module'] . '/' . $nav_info['guide'];
        }
        $nav_info['file_number'] = Check::fileNumber($nav_info['icon']) ? $nav_info['icon'] : '';
        $nav_info['icon'] = Check::fileNumber($nav_info['icon']) ? attachment()->url($nav_info['icon']) : $nav_info['icon'];

        return array(
            'nav_info' => $nav_info,
            'type' => $type,
            'catalog_list' => $this->navService->buildNavTargetList($nav_info['module'], $nav_info['guide'], 'miniprogram'),
        );
    }

    /**
     * @param array $data validated
     * @return int 新增记录主键
     */
    public function storeNav(array $data)
    {
        $this->miniprogramService->defineCodePath();
        $navMenuRaw = isset($data['nav_menu']) ? trim((string) $data['nav_menu']) : '';
        $parts = $navMenuRaw !== '' ? explode(',', $navMenuRaw) : array('', '');
        $module = isset($parts[0]) ? trim($parts[0]) : '';
        $guide = '';
        if ($module === 'nav') {
            $guide = isset($data['guide']) ? trim((string) $data['guide']) : '';
        } else {
            $guide = isset($parts[1]) ? trim($parts[1]) : '';
        }
        $type = isset($data['type']) ? $data['type'] : '';
        if (!in_array($type, array('miniprogram_top', 'miniprogram_tabbar'), true)) {
            throw new DomainException(lang('illegal'), route('admin.miniprogram.nav'));
        }
        $row = array(
            'module' => $module,
            'name' => $data['name'],
            'icon' => '',
            'guide' => $guide,
            'parent_id' => 0,
            'type' => $type,
            'sort' => isset($data['sort']) ? Num::toIntOrZero($data['sort']) : 0,
        );
        $model = MiniprogramNav::create($row);
        $auto_id = (int) $model->getKey();

        $icon = attachment()->store('nav', $auto_id, UploadedFile::fromGlobals('icon'), 'main', AttachmentUploadOptions::create()->withUploader('admin', (int) auth('admin')->id()));
        if ($icon !== '') {
            MiniprogramNav::whereKey($auto_id)->update(array('icon' => $icon));
        }

        $this->miniprogramService->syncMiniprogramConfig();
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CREATE, 1, (string) $data['name'], 'miniprogram_nav');

        return $auto_id;
    }

    /**
     * @param array $data validated
     * @return void
     */
    public function updateNav(array $data)
    {
        $this->miniprogramService->defineCodePath();
        $id = isset($data['id']) ? Num::toIntOrZero($data['id']) : 0;
        if ($id < 1) {
            throw new DomainException(lang('illegal'), route('admin.miniprogram.nav'));
        }
        if (!MiniprogramNav::findMiniprogramNav($id)) {
            throw new DomainException(lang('illegal'), route('admin.miniprogram.nav'));
        }
        $type = isset($data['type']) ? $data['type'] : MiniprogramNav::getTypeById($id);
        if (!in_array($type, array('miniprogram_top', 'miniprogram_tabbar'), true)) {
            throw new DomainException(lang('illegal'), route('admin.miniprogram.nav'));
        }
        $new_icon = attachment()->store('nav', $id, UploadedFile::fromGlobals('icon'), 'main', AttachmentUploadOptions::create()->withUploader('admin', (int) auth('admin')->id()));
        $update_data = array(
            'name' => $data['name'],
            'status' => isset($data['status']) ? Num::toIntOrZero($data['status']) : 0,
            'sort' => isset($data['sort']) ? Num::toIntOrZero($data['sort']) : 0,
        );
        if ($new_icon) {
            $update_data['icon'] = $new_icon;
        }
        $navMenuRaw = isset($data['nav_menu']) ? trim((string) $data['nav_menu']) : '';
        if ($navMenuRaw !== '') {
            $nav_menu = explode(',', $navMenuRaw);
            $update_data['module'] = isset($nav_menu[0]) ? trim($nav_menu[0]) : '';
            $update_data['guide'] = isset($nav_menu[1]) ? trim($nav_menu[1]) : '';
        } else {
            if (isset($data['guide'])) {
                $update_data['guide'] = $data['guide'];
            }
        }
        $model = MiniprogramNav::find($id);
        if (!$model) {
            throw new DomainException(lang('illegal'), route('admin.miniprogram.nav'));
        }
        $model->fill($update_data, 'update')->save();
        $this->miniprogramService->syncMiniprogramConfig();
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $data['name'], 'miniprogram_nav');
    }

    /**
     * @param int   $id
     * @param array $post
     * @return array{message: string, back_url: string, timeout: string, confirm_url: string}
     * @throws DomainException
     */
    public function deleteNav($id, array $post)
    {
        $this->miniprogramService->defineCodePath();
        $id = (int) $id;
        if ($id < 1) {
            throw new DomainException(lang('illegal'), route('admin.miniprogram.nav'));
        }
        $nav = MiniprogramNav::findMiniprogramNav($id, 'name, type');
        if (!$nav) {
            throw new DomainException(lang('illegal'), route('admin.miniprogram.nav'));
        }
        $back = route('admin.miniprogram.nav', array(), array('query' => array('type' => $nav['type'])));
        if (isset($post['confirm'])) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $nav['name'], 'miniprogram_nav');
            MiniprogramNav::destroy($id);
            $this->miniprogramService->syncMiniprogramConfig();

            return array(
                'message' => lang('del_succes'),
                'back_url' => $back,
            );
        }
        $del_check = preg_replace('/d%/Ums', $nav['name'], lang('del_check'));
        return array(
            'message' => $del_check,
            'back_url' => $back,
            'timeout' => '30',
            'confirm_url' => route('admin.miniprogram.nav.destroy', array('id' => $id)),
        );
    }
}
