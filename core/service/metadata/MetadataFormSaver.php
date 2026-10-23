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

namespace Dou\Core\Service\Metadata;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Content\MarkdownRenderer;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 扩展字段表单值的解析、附件占位与提交编排。
 *
 * 调用顺序：
 *   begin() → 业务行写入（metadata=$plan['json']）→ commit()（事务内）→ purge()
 *   失败时 abort() 释放本次新上传的草稿。
 */
class MetadataFormSaver extends BaseService
{
    /** @var MetadataTranslationStore */
    private $translations;

    public function __construct()
    {
        $this->translations = new MetadataTranslationStore();
    }

    /**
     * 解析提交数据，产出可写入计划；未提交 metadata_present 时返回 null。
     *
     * @param string $module
     * @param array $data FormRequest::validated()
     * @param int $itemId 0 = 新建
     * @param string $existingRaw 现有默认语言 JSON
     * @param int $adminId
     * @return array|null
     * @throws DomainException
     */
    public function begin($module, array $data, $itemId, $existingRaw, $adminId)
    {
        if (empty($data['metadata_present'])) {
            return null;
        }
        attachment()->cleanupUserDrafts('admin', (int) $adminId, $module);
        $schema = MetadataService::instance()->schema($module);
        if (empty($schema)) {
            return null;
        }

        $defaultLang = $this->translations->defaultLanguage();
        $inputs = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : array();
        $inherit = isset($data['metadata_inherit']) && is_array($data['metadata_inherit']) ? $data['metadata_inherit'] : array();
        $clears = isset($data['metadata_clear']) && is_array($data['metadata_clear']) ? $data['metadata_clear'] : array();

        $existingDefault = MetadataValues::decode($existingRaw);
        $existingTranslations = (int) $itemId > 0 ? $this->translations->all($module, $itemId) : array();

        $plan = array(
            'module' => (string) $module,
            'item_id' => (int) $itemId,
            'default_lang' => $defaultLang,
            'admin_id' => (int) $adminId,
            'reservations' => array(),
            'purge' => array(),
        );

        $defaultMap = $this->buildMap($module, $schema, $defaultLang, $inputs, $clears, $existingDefault, true, $inherit, $plan);
        $errors = MetadataSchema::validateValues($schema, $defaultMap);
        if (!empty($errors)) {
            throw new DomainException(implode('；', $errors));
        }

        $translationMaps = array();
        $deleteTranslations = array();
        $languagePacks = array();
        foreach ($this->translations->languagePacks() as $row) {
            $languagePacks[] = $row['language_pack'];
        }
        foreach ($languagePacks as $pack) {
            $existingMap = isset($existingTranslations[$pack]) ? $existingTranslations[$pack] : array();
            $map = $this->buildMap($module, $schema, $pack, $inputs, $clears, $existingMap, false, $inherit, $plan);
            $errors = MetadataSchema::validateValues($schema, $map, array('skip_required' => true));
            if (!empty($errors)) {
                throw new DomainException(implode('；', $errors));
            }
            if (empty($map)) {
                $deleteTranslations[] = $pack;
            } else {
                $translationMaps[$pack] = $map;
            }
        }

        $existingNumbers = MetadataValues::collectNumbers($schema, $existingDefault);
        $activePacks = array();
        foreach ($languagePacks as $pack) {
            $activePacks[$pack] = true;
        }
        foreach ($existingTranslations as $pack => $map) {
            // 已从 dou_language 移除的语言行保持原样，不参与本次附件回收
            if (isset($activePacks[$pack])) {
                $existingNumbers += MetadataValues::collectNumbers($schema, $map);
            }
        }
        $newNumbers = MetadataValues::collectNumbers($schema, $defaultMap);
        foreach ($translationMaps as $map) {
            $newNumbers += MetadataValues::collectNumbers($schema, $map);
        }

        $plan['purge'] = array_keys(array_diff_key($existingNumbers, $newNumbers));
        $plan['json'] = MetadataValues::encode($defaultMap);
        $plan['default_map'] = $defaultMap;
        $plan['translations'] = $translationMaps;
        $plan['delete_translations'] = $deleteTranslations;
        $plan['reservations'] = array_values(array_unique($plan['reservations']));

        return $plan;
    }

    /**
     * 事务内提交：先写翻译（随事务回滚），最后认领附件。
     *
     * @param array|null $plan
     * @param int $itemId
     * @param int $adminId
     * @return void
     * @throws DomainException
     */
    public function commit($plan, $itemId, $adminId)
    {
        if (empty($plan)) {
            return;
        }

        $maps = $plan['translations'];
        foreach ($plan['delete_translations'] as $pack) {
            $maps[$pack] = array();
        }
        $this->translations->save($plan['module'], $itemId, $maps, $plan['default_lang']);

        foreach ($plan['reservations'] as $number) {
            if (attachment()->adopt($plan['module'], $number, $itemId, 'admin', $adminId) < 1) {
                throw new DomainException(lang('file_claim_failed', '附件认领失败'));
            }
        }
    }

    /**
     * 失败时释放本次新上传的草稿。
     *
     * @param array|null $plan
     * @return void
     */
    public function abort($plan)
    {
        if (empty($plan) || empty($plan['reservations'])) {
            return;
        }
        foreach ($plan['reservations'] as $number) {
            try {
                attachment()->release($plan['module'], $number, 'admin', (int) $plan['admin_id']);
            } catch (\Exception $e) {
                // 请求结束的 shutdown 清理仍会兜底
            }
        }
    }

    /**
     * 提交成功后删除被替换/清空的旧附件。
     *
     * @param array|null $plan
     * @param int $itemId
     * @return void
     */
    public function purge($plan, $itemId)
    {
        if (empty($plan) || empty($plan['purge'])) {
            return;
        }
        MetadataValues::purgeNumbers($plan['module'], $itemId, $plan['purge']);
    }

    /**
     * 构建某语言的完整 map（保留禁用/未知字段的现有值）。
     *
     * @param string $module
     * @param array $schema
     * @param string $lang
     * @param array $inputs
     * @param array $clears
     * @param array $existingMap
     * @param bool $isDefault
     * @param array $inherit
     * @param array $plan
     * @return array
     * @throws DomainException
     */
    private function buildMap($module, array $schema, $lang, array $inputs, array $clears, array $existingMap, $isDefault, array $inherit, array &$plan)
    {
        $map = $existingMap;

        foreach ($schema as $slug => $field) {
            $inherited = !$isDefault && !empty($inherit[$lang][$slug]);
            if ($inherited) {
                unset($map[$slug]);
                continue;
            }

            $value = $this->readValue($module, $field, $lang, $inputs, $clears, isset($existingMap[$slug]) ? $existingMap[$slug] : null, $plan);
            if ($value === null) {
                if ($isDefault) {
                    unset($map[$slug]);
                } else {
                    $map[$slug] = $this->emptyValue($field['type']);
                }
            } else {
                $map[$slug] = $value;
            }
        }

        return $map;
    }

    /**
     * @param string $module
     * @param array $field
     * @param string $lang
     * @param array $inputs
     * @param array $clears
     * @param mixed $existingValue
     * @param array $plan
     * @return mixed null = 清空/未填
     * @throws DomainException
     */
    private function readValue($module, array $field, $lang, array $inputs, array $clears, $existingValue, array &$plan)
    {
        $slug = $field['slug'];
        $type = $field['type'];
        $raw = null;
        if (isset($inputs[$lang]) && is_array($inputs[$lang]) && array_key_exists($slug, $inputs[$lang])) {
            $raw = $inputs[$lang][$slug];
        }
        $clear = isset($clears[$lang]) && !empty($clears[$lang][$slug]);

        if ($type === 'image' || $type === 'file') {
            $fileField = 'mf_' . $lang . '_' . $slug;
            $file = UploadedFile::fromGlobals($fileField);
            if ($file instanceof UploadedFile && $file->isValid()) {
                return $this->reserve($module, $file, $field, $lang, $plan);
            }
            if ($clear) {
                return null;
            }

            $hidden = is_scalar($raw) ? trim((string) $raw) : '';
            $existing = is_string($existingValue) ? $existingValue : '';
            if ($hidden !== '') {
                if ($hidden === $existing) {
                    return $hidden;
                }
                if ($this->isDraftNumber($module, $hidden, $slug, isset($plan['admin_id']) ? (int) $plan['admin_id'] : 0)) {
                    $plan['reservations'][] = $hidden;

                    return $hidden;
                }
                throw new DomainException(lang('metadata_error_attachment_tamper', '附件编号与当前记录不一致，请刷新后重试'));
            }

            return null;
        }

        if ($type === 'gallery') {
            $limit = MetadataSchema::limitFor($field);
            $existingList = is_array($existingValue) ? array_values($existingValue) : array();
            $slotCount = max($limit, count($existingList));
            $slotCount = min($slotCount, MetadataSchema::MAX_MULTI_LIMIT);
            $result = array();

            for ($i = 0; $i < $slotCount; $i++) {
                $fileField = 'mf_' . $lang . '_' . $slug . '_' . $i;
                $file = UploadedFile::fromGlobals($fileField);
                if ($file instanceof UploadedFile && $file->isValid()) {
                    $result[] = $this->reserve($module, $file, $field, $lang, $plan);
                    continue;
                }

                $hidden = is_array($raw) && isset($raw[$i]) ? trim((string) $raw[$i]) : '';
                if ($hidden === '') {
                    continue;
                }
                if (!in_array($hidden, $existingList, true)) {
                    throw new DomainException(lang('metadata_error_attachment_tamper', '附件编号与当前记录不一致，请刷新后重试'));
                }
                $result[] = $hidden;
            }

            return MetadataSchema::normalizeValue($field, $result);
        }

        if ($type === 'checkbox') {
            return MetadataSchema::normalizeValue($field, is_array($raw) ? $raw : array());
        }

        if ($type === 'switch') {
            $value = $raw === null ? 0 : $raw;

            return MetadataSchema::normalizeValue($field, $value);
        }

        $value = is_scalar($raw) ? (string) $raw : '';
        if ($type === 'editor') {
            $renderer = new MarkdownRenderer();
            $value = $renderer->toStore($value);
        }

        return MetadataSchema::normalizeValue($field, $value);
    }

    /**
     * @param string $module
     * @param UploadedFile $file
     * @param array $field
     * @param string $lang
     * @param array $plan
     * @return string
     * @throws DomainException
     */
    private function reserve($module, UploadedFile $file, array $field, $lang, array &$plan)
    {
        $options = AttachmentUploadOptions::create()
            ->withUploader('admin', isset($plan['admin_id']) ? (int) $plan['admin_id'] : 0);

        if ($field['type'] === 'file') {
            $effective = MetadataSchema::effectiveFileOptions($field);
            $options->withAllowExtensions($effective['extensions'])->withUploadMaxKb($effective['max_kb']);
        }

        $number = attachment()->reserve(
            $module,
            $file,
            'admin',
            isset($plan['admin_id']) ? (int) $plan['admin_id'] : 0,
            'metadata:' . $field['slug'],
            $options
        );
        if ($number === '') {
            throw new DomainException(lang('metadata_error_upload', '文件上传失败'));
        }

        $plan['reservations'][] = $number;

        return $number;
    }

    /**
     * 是否为当前 admin 在本模块/字段下预上传的 metadata draft。
     *
     * 抽屉内文件无法回填主表单，先 storeDraft 为 draft；主表单提交时在这里接受并 adopt。
     *
     * @param string $module
     * @param string $number
     * @param string $slug
     * @param int $adminId
     * @return bool
     */
    private function isDraftNumber($module, $number, $slug, $adminId)
    {
        if ($adminId <= 0 || $number === '' || $slug === '') {
            return false;
        }

        return (bool) DB::table('file')
            ->where('number', $number)
            ->where('module', (string) $module)
            ->where('uploader_type', 'admin')
            ->where('uploader_id', $adminId)
            ->where('status', 'draft')
            ->where('type', 'metadata:' . (string) $slug)
            ->find();
    }

    /**
     * 非默认语言的显式空值。
     *
     * @param string $type
     * @return mixed
     */
    private function emptyValue($type)
    {
        if (MetadataSchema::isMulti($type)) {
            return array();
        }
        if ($type === 'switch') {
            return 0;
        }

        return '';
    }
}
