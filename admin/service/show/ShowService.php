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

namespace Dou\Admin\Service\Show;

use Dou\Admin\Model\Show\Show;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Num;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台幻灯（route=show/...）；列表 `buildShowListData`；持久化 `insert`/`update`；删除 `delete`。
 */
class ShowService extends BaseService
{
    /**
     * 列表页数据（右侧表格），与前台 CollectionService::getShowList 展示字段一致。
     *
     * @param string $type
     * @return array list
     */
    public function buildShowListData($type)
    {
        // AR：translatable/casts/prefetchers 接管 langBox 与 image URL
        $rows = Show::listByType($type);
        $show_list = array();

        foreach ($rows as $model) {
            $row = $model->toArray();

            $text_array = array();
            if (isset($row['text']) && preg_match("(\r)", $row['text'])) {
                $show_text = str_replace("\r\n", "\r", $row['text']);
                $text_array = explode("\r", $show_text);
            }

            $show_list[] = array(
                'id' => $row['id'],
                'name' => $row['name'],
                'link' => $row['link'],
                'image' => $row['image'],
                'text' => $row['text'],
                'text_array' => $text_array,
                'sort' => $row['sort'],
            );
        }

        return array(
            'list' => $show_list,
        );
    }

    /**
     * 首页左侧新增表单默认值。
     *
     * @return array
     */
    public function buildShowDefaultData()
    {
        return array(
            'id' => 0,
            'name' => '',
            'image' => '',
            'link' => '',
            'text' => '',
            'sort' => 50,
        );
    }

    /**
     * 新增提交（字段来自 ShowFormRequest::validated()）。
     *
     * @param array $data
     * @param string $type pc|miniprogram
     * @return int 新增记录主键（供 Controller 跳转编辑页）
     */
    public function insert(array $data, $type)
    {
        $row = array(
            'name' => $data['name'],
            'link' => Arr::get($data, 'link', ''),
            'text' => Arr::get($data, 'text', ''),
            'image' => '',
            'sort' => Num::toIntOrZero(Arr::get($data, 'sort', 0)),
            'type' => $type,
        );

        $model = Show::create($row);
        $id = (int) $model->getKey();

        if (isset($_FILES['image']['name']) && $_FILES['image']['name'] != '') {
            $image = attachment()->store('show', $id, UploadedFile::fromGlobals('image'), 'main', AttachmentUploadOptions::create()->withUploader('admin', (int) auth('admin')->id()));
            if ($image !== '') {
                Show::whereKey($id)->update(array('image' => $image));
            }
        }

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CREATE, 1, (string) $data['name']);

        return $id;
    }

    /**
     * 更新提交（字段来自 ShowFormRequest::validated()）。
     *
     * @param array $data
     * @return void
     */
    public function update(array $data)
    {
        $id = Num::toIntOrZero(Arr::get($data, 'id', 0));
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.show'));
        }

        $show = Show::find($id);
        if (!$show) {
            throw new DomainException(lang('illegal'), route('admin.show'));
        }

        $new_image = '';
        if (isset($_FILES['image']['name']) && $_FILES['image']['name'] != '') {
            $new_image = attachment()->store('show', $id, UploadedFile::fromGlobals('image'), 'main', AttachmentUploadOptions::create()->withUploader('admin', (int) auth('admin')->id()));
        }

        $update_data = array(
            'name' => $data['name'],
            'link' => Arr::get($data, 'link', ''),
            'text' => Arr::get($data, 'text', ''),
            'sort' => Num::toIntOrZero(Arr::get($data, 'sort', 0)),
        );

        if ($new_image !== '') {
            $update_data['image'] = $new_image;
        }

        $show->fill($update_data, 'update')->save();

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $data['name']);
    }

    /**
     * @param int $id
     * @return array|null
     */
    public function buildShowEditData($id)
    {
        $model = Show::find((int) $id);
        if (!$model) {
            return null;
        }
        $show = $model->getAttributes();
        $show['file_number'] = $show['image'];
        $show['image'] = attachment()->url($show['image']);

        return $show;
    }

    /**
     * 单条删除：二次确认或执行删除
     *
     * @param int $id
     * @param array $data
     * @return array{message: string, back_url: string, timeout: string, confirm_url: string}
     * @throws DomainException 需确认时抛出，由入口 `$context->message->respond()`；已确认则删除并返回
     */
    public function delete($id, array $data)
    {
        $id = (int) $id;
        if ($id < 1) {
            throw new DomainException(lang('illegal'), route('admin.show'));
        }

        $model = Show::find($id, 'name, image');
        if (!$model) {
            throw new DomainException(lang('illegal'), route('admin.show'));
        }
        $show = $model->getAttributes();

        $showName = isset($show['name']) ? $show['name'] : '';
        $showName = $showName ? $showName : '';

        if (isset($data['confirm'])) {
            $showImg = isset($show['image']) ? $show['image'] : '';
            attachment()->delete($showImg);
            language()->deleteLang('show', $id);
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $showName);
            Show::destroy($id);

            return array(
                'message' => lang('del_succes'),
                'back_url' => route('admin.show'),
            );
        }

        $delCheck = preg_replace('/d%/Ums', $showName, lang('del_check'));
        return array(
            'message' => $delCheck,
            'back_url' => route('admin.show'),
            'timeout' => '30',
            'confirm_url' => route('admin.show.destroy', array('id' => $id)),
        );
    }
}
