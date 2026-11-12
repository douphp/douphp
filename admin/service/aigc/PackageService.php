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

namespace Dou\Admin\Service\Aigc;

use Dou\Admin\Model\Aigc\AigcPackage;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 套餐（aigc_package，route=aigc/package/...）。
 */
class PackageService extends BaseService
{
    /**
     * 列表页数据。
     *
     * @return array package_list
     */
    public function getAdminIndexPageData()
    {
        $rows = AigcPackage::applyDefaultOrder()->get();
        $package_list = array();
        foreach ($rows as $model) {
            $row = $model->toArray();
            $tokens = (int) $model->getRawAttribute('tokens_limit');
            $requests = (int) $model->getRawAttribute('requests_limit');
            $package_list[] = array(
                'id' => $row['id'],
                'name' => $row['name'],
                'day' => $row['day'],
                'tokens_limit' => $tokens,
                'tokens_limit_format' => $tokens > 0 ? number_format($tokens) : lang('aigc_package_unlimited'),
                'requests_limit' => $requests,
                'requests_limit_format' => $requests > 0 ? number_format($requests) : lang('aigc_package_unlimited'),
                'price' => Util::formatPrice($model->getRawAttribute('price')),
                'image' => $row['image'],
                'sort' => $row['sort'],
                'status' => $row['status'],
                'status_format' => language()->dataLangFormat('aigc_package_status_', $row['status']),
            );
        }

        return array(
            'package_list' => $package_list,
        );
    }

    /**
     * 新增页数据。
     *
     * @return array form_action
     */
    public function getAddFormData()
    {
        return array(
            'form_action' => 'store',
        );
    }

    /**
     * 编辑页数据。
     *
     * @param mixed $id 套餐主键
     * @return array|null package、form_action
     */
    public function getEditFormData($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $packageRow = AigcPackage::find($id);
        if (!$packageRow) {
            return null;
        }
        $package = $packageRow->getAttributes();
        $package['file_number'] = $package['image'];
        $package['image'] = attachment()->url($package['image']);

        return array(
            'package' => $package,
            'form_action' => 'update',
        );
    }

    /**
     * 新增提交（已通过 PackageFormRequest 校验与白名单）。
     *
     * @param array $data
     * @return int 新增记录主键
     */
    public function insert(array $data)
    {
        $row = array(
            'name' => trim($data['name']),
            'day' => isset($data['day']) ? intval($data['day']) : 0,
            'tokens_limit' => isset($data['tokens_limit']) ? intval($data['tokens_limit']) : 0,
            'requests_limit' => isset($data['requests_limit']) ? intval($data['requests_limit']) : 0,
            'price' => isset($data['price']) && $data['price'] !== '' ? floatval($data['price']) : 0,
            'content' => isset($data['content']) ? xss()->content($data['content']) : '',
            'sort' => isset($data['sort']) ? intval($data['sort']) : 0,
            'status' => isset($data['status']) ? intval($data['status']) : 0,
        );

        $adminId = (int) auth('admin')->id();
        $image = attachment()->reserve('aigc_package', UploadedFile::fromGlobals('image'), 'admin', $adminId, 'main', AttachmentUploadOptions::create());
        $row['image'] = $image;

        $newId = (int) DB::transaction(function () use ($row, $image, $adminId) {
            $model = AigcPackage::create($row);
            if (!$model) {
                throw new DomainException(lang('operation_failed'));
            }
            $newId = (int) $model->getKey();
            if ($newId <= 0) {
                throw new DomainException(lang('operation_failed'));
            }

            if ($image !== '' && attachment()->adopt('aigc_package', $image, $newId, 'admin', $adminId) < 1) {
                throw new DomainException(lang('file_claim_failed'));
            }

            return $newId;
        });

        audit()->writeAdminLog($adminId, AdminLogAction::CREATE, 1, (string) $row['name'], 'aigc_package');

        return $newId;
    }

    /**
     * 更新提交。
     *
     * @param array $data
     * @return void
     */
    public function update(array $data)
    {
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.aigc.package'));
        }

        $record = AigcPackage::find($id);
        if (!$record) {
            throw new DomainException(lang('illegal'), route('admin.aigc.package'));
        }
        $recordAttributes = $record->getAttributes();
        $oldImageNumber = isset($recordAttributes['image']) ? (string) $recordAttributes['image'] : '';

        $uploaded_image = attachment()->store('aigc_package', $id, UploadedFile::fromGlobals('image'), 'main', AttachmentUploadOptions::create()->withUploader('admin', (int) auth('admin')->id()));
        $deleteImage = ($uploaded_image === '' && !empty($data['image_delete']));

        $row = array(
            'name' => trim($data['name']),
            'day' => isset($data['day']) ? intval($data['day']) : 0,
            'tokens_limit' => isset($data['tokens_limit']) ? intval($data['tokens_limit']) : 0,
            'requests_limit' => isset($data['requests_limit']) ? intval($data['requests_limit']) : 0,
            'price' => isset($data['price']) && $data['price'] !== '' ? floatval($data['price']) : 0,
            'content' => isset($data['content']) ? xss()->content($data['content']) : '',
            'sort' => isset($data['sort']) ? intval($data['sort']) : 0,
            'status' => isset($data['status']) ? intval($data['status']) : 0,
        );
        if ($uploaded_image) {
            $row['image'] = $uploaded_image;
        } elseif ($deleteImage) {
            $row['image'] = '';
        }

        $record->fill($row, 'update')->save();
        if ($deleteImage && $oldImageNumber !== '') {
            attachment()->delete($oldImageNumber);
        }

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $row['name'], 'aigc_package');
    }

    /**
     * 单条删除（二次确认；已有订阅记录的套餐拒绝删除）。
     *
     * @param mixed $id 套餐主键
     * @param array $post 含 confirm 时表示用户已确认
     * @return array{message: string, back_url: string, timeout?: string, confirm_url?: string}
     * @throws DomainException
     */
    public function delete($id, array $post)
    {
        $id = (int) $id;
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.aigc.package'));
        }
        $name = AigcPackage::whereKey($id)->value('name');
        if ($name === null || $name === '') {
            throw new DomainException(lang('illegal'), route('admin.aigc.package'));
        }

        if (AigcPackage::subscriptionExistsForPackage($id)) {
            throw new DomainException(lang('aigc_package_has_subscription'), route('admin.aigc.package'));
        }

        if (isset($post['confirm'])) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $name, 'aigc_package');
            AigcPackage::destroy($id);

            return array(
                'message' => lang('del_succes'),
                'back_url' => route('admin.aigc.package'),
            );
        }

        $msg = preg_replace('/d%/Ums', $name, lang('del_check'));
        return array(
            'message' => $msg,
            'back_url' => route('admin.aigc.package'),
            'timeout' => '30',
            'confirm_url' => route('admin.aigc.package.destroy', array('id' => $id)),
        );
    }

    /**
     * 列表批量操作。
     *
     * @param array $post checkbox、action 等表单字段
     * @return array{message: string, back_url: string}
     * @throws DomainException
     */
    public function batch(array $post)
    {
        if (empty($post['checkbox']) || !is_array($post['checkbox'])) {
            throw new DomainException(lang('aigc_package_select_empty'), route('admin.aigc.package'));
        }

        $action = Arr::get($post, 'action', '');

        if ($action === 'del_all') {
            $ids = Check::intIds($post['checkbox']);
            if (empty($ids)) {
                throw new DomainException(lang('aigc_package_select_empty'), route('admin.aigc.package'));
            }
            foreach ($ids as $pid) {
                if (AigcPackage::subscriptionExistsForPackage($pid)) {
                    throw new DomainException(lang('aigc_package_has_subscription'), route('admin.aigc.package'));
                }
            }
            AigcPackage::whereIn('id', $ids)->delete();
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'bulk:AigcPackage (' . count($ids) . ' items)', 'aigc_package');
            return array('message' => lang('del_succes'), 'back_url' => route('admin.aigc.package'));
        }

        throw new DomainException(lang('select_empty'), route('admin.aigc.package'));
    }
}
