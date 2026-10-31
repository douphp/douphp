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

namespace Dou\Admin\Service\Metadata;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\Attachment\LanguagePath;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Metadata\MetadataModules;
use Dou\Core\Service\Metadata\MetadataSchema;
use Dou\Core\Service\Metadata\MetadataService;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 扩展字段单字段抽屉的草稿附件上传/释放。
 *
 * 抽屉内选择的文件无法在关闭后回填主表单，因此这里先 reserve 为 draft，
 * 主表单提交时由 MetadataFormSaver 接受并 adopt；放弃表单由 cleanupUserDrafts 清理。
 */
class MetadataDraftService extends BaseService
{
    /**
     * @param array $data
     * @return array
     * @throws DomainException
     */
    public function upload(array $data)
    {
        $module = isset($data['module']) && Check::basicString($data['module']) ? (string) $data['module'] : '';
        $slug = isset($data['slug']) && Check::basicString($data['slug']) ? (string) $data['slug'] : '';
        if ($module === '' || $slug === '') {
            throw new DomainException(lang('illegal'));
        }
        if (!MetadataModules::exists($module)) {
            throw new DomainException(lang('metadata_error_module', '所属模块不合法'));
        }

        $schema = MetadataService::instance()->schema($module, true);
        if (!isset($schema[$slug]) || !in_array($schema[$slug]['type'], array('image', 'file'), true)) {
            throw new DomainException(lang('metadata_error_upload', '文件上传失败'));
        }
        $field = $schema[$slug];
        $file = UploadedFile::fromGlobals('file');
        if (!($file instanceof UploadedFile) || !$file->isValid()) {
            throw new DomainException(lang('metadata_error_upload', '文件上传失败'));
        }

        $adminId = (int) auth('admin')->id();
        if ($adminId <= 0) {
            throw new DomainException(lang('illegal'));
        }

        $options = AttachmentUploadOptions::create()
            ->withUploader('admin', $adminId)
            ->withLanguagePack(LanguagePath::normalize(isset($data['language_pack']) ? $data['language_pack'] : ''));
        if ($field['type'] === 'file') {
            $effective = MetadataSchema::effectiveFileOptions($field);
            $options->withAllowExtensions($effective['extensions'])->withUploadMaxKb($effective['max_kb']);
        }

        attachment()->cleanupUserDrafts('admin', $adminId, $module);
        $draftToken = attachment()->newDraftToken('admin', $adminId);
        $number = attachment()->storeDraft($module, $file, 'admin', $adminId, $draftToken, 'metadata:' . $slug, $options);
        if ($number === '') {
            throw new DomainException(lang('metadata_error_upload', '文件上传失败'));
        }

        return array(
            'ok' => true,
            'number' => $number,
            'url' => attachment()->url($number),
        );
    }

    /**
     * 释放抽屉内新上传、但用户已清除/替换的 draft。
     *
     * @param array $data
     * @return array
     * @throws DomainException
     */
    public function release(array $data)
    {
        $module = isset($data['module']) && Check::basicString($data['module']) ? (string) $data['module'] : '';
        $number = isset($data['number']) && Check::fileNumber($data['number']) ? (string) $data['number'] : '';
        if ($module === '' || $number === '') {
            throw new DomainException(lang('illegal'));
        }

        $adminId = (int) auth('admin')->id();
        if ($adminId <= 0) {
            throw new DomainException(lang('illegal'));
        }

        $row = DB::table('file')
            ->where('number', $number)
            ->where('module', $module)
            ->where('uploader_type', 'admin')
            ->where('uploader_id', $adminId)
            ->where('status', 'draft')
            ->find();
        if ($row) {
            attachment()->release($module, $number, 'admin', $adminId);
        }

        return array('ok' => true);
    }
}
