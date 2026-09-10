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

use Dou\Admin\Model\Miniprogram\MiniprogramShow;
use Dou\Admin\Model\Show\Show;
use Dou\Core\Filesystem\Storage;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Num;
use Dou\Core\Web\Http\UploadedFile;
if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台小程序幻灯（show.type = miniprogram）
 */
class MiniprogramShowService extends BaseService
{
    /** @var \Dou\Core\Filesystem\Disk */
    private $slideBase;

    public function __construct()
    {
        $this->slideBase = Storage::build('images/slide/' . MINIPROGRAM_DIR . '/');
    }

    /**
     * 列表页左侧「添加幻灯」表单默认值（键齐全，避免 Smarty/PHP 8 对 null 取下标）。
     *
     * @return array
     */
    public function buildMiniprogramShowDefaultForm()
    {
        return array(
            'id' => '',
            'name' => '',
            'link' => '',
            'image' => '',
            'sort' => 50,
        );
    }

    /**
     * @return array show_list
     */
    public function buildMiniprogramShowListData()
    {
        return array(
            'show_list' => Show::showList('miniprogram'),
        );
    }

    /**
     * @param int $id
     * @return array|null
     */
    public function buildMiniprogramShowEditData($id)
    {
        $id = (int) $id;
        if ($id < 1) {
            return null;
        }
        $show = MiniprogramShow::findMiniprogramShow($id);
        if (!$show) {
            return null;
        }
        $show = $show->getAttributes();
        $show['file_number'] = $show['image'];
        $show['image'] = attachment()->url($show['image']);

        return $show;
    }

    /**
     * @param array $data validated
     * @return int 新增记录主键
     */
    public function storeShow(array $data)
    {
        $row = array(
            'name' => $data['name'],
            'link' => isset($data['link']) ? $data['link'] : '',
            'image' => '',
            'type' => 'miniprogram',
            'sort' => isset($data['sort']) ? Num::toIntOrZero($data['sort']) : 0,
        );
        $model = MiniprogramShow::create($row);
        $newId = (int) $model->getKey();

        if (!empty($_FILES['image']['name'])) {
            $image = attachment()->store('show', $newId, UploadedFile::fromGlobals('image'), 'main', AttachmentUploadOptions::create()->withUploader('admin', (int) auth('admin')->id()));
            if ($image !== '') {
                MiniprogramShow::whereKey($newId)->update(array('image' => $image));
            }
        }

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CREATE, 1, (string) $data['name'], 'miniprogram_show');

        return $newId;
    }

    /**
     * @param array $data validated
     * @return void
     */
    public function updateShow(array $data)
    {
        $id = isset($data['id']) ? Num::toIntOrZero($data['id']) : 0;
        if ($id < 1 || !MiniprogramShow::existsMiniprogramShow($id)) {
            throw new DomainException(lang('illegal'), route('admin.miniprogram.show'));
        }
        $new_image = '';
        if (!empty($_FILES['image']['name'])) {
            $new_image = attachment()->store('show', $id, UploadedFile::fromGlobals('image'), 'main', AttachmentUploadOptions::create()->withUploader('admin', (int) auth('admin')->id()));
        }
        $update_data = array(
            'name' => $data['name'],
            'link' => isset($data['link']) ? $data['link'] : '',
            'sort' => isset($data['sort']) ? Num::toIntOrZero($data['sort']) : 0,
        );
        if ($new_image) {
            $update_data['image'] = $new_image;
        }
        $model = MiniprogramShow::find($id);
        if (!$model) {
            throw new DomainException(lang('illegal'), route('admin.miniprogram.show'));
        }
        $model->fill($update_data, 'update')->save();
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $data['name'], 'miniprogram_show');
    }

    /**
     * @param int   $id
     * @param array $data
     * @return array{message: string, back_url: string, timeout: string, confirm_url: string}
     * @throws DomainException
     */
    public function deleteShow($id, array $data)
    {
        $id = (int) $id;
        if ($id < 1) {
            throw new DomainException(lang('illegal'), route('admin.miniprogram.show'));
        }
        $row = MiniprogramShow::findMiniprogramShow($id, 'name, image');
        if (!$row) {
            throw new DomainException(lang('illegal'), route('admin.miniprogram.show'));
        }
        $show_name = isset($row['name']) ? $row['name'] : '';
        if (isset($data['confirm'])) {
            if (!empty($row['image'])) {
                attachment()->delete($row['image']);
            }
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $show_name, 'miniprogram_show');
            MiniprogramShow::destroy($id);

            return array(
                'message' => lang('del_succes'),
                'back_url' => route('admin.miniprogram.show'),
            );
        }
        $del_check = preg_replace('/d%/Ums', $show_name, lang('del_check'));
        return array(
            'message' => $del_check,
            'back_url' => route('admin.miniprogram.show'),
            'timeout' => '30',
            'confirm_url' => route('admin.miniprogram.show.destroy', array('id' => $id)),
        );
    }
}
