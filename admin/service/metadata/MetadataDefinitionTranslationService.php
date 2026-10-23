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
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Metadata\MetadataService;
use Dou\Core\Service\Metadata\MetadataTranslationStore;
use Dou\Core\Support\Check;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 扩展字段定义文案（显示名 / 选项）的多语言草稿存取。
 *
 * 草稿随定义表单提交，在定义 create/update 后统一写 language_value；
 * module='metadata'、item_id=定义 id、field='label'|'options'。
 */
class MetadataDefinitionTranslationService extends BaseService
{
    /** @var MetadataTranslationStore */
    private $store;

    public function __construct()
    {
        $this->store = new MetadataTranslationStore();
    }

    /**
     * 编辑页草稿初值与按钮状态。
     *
     * @param int $id
     * @return array {drafts: array, has_value: array}
     */
    public function load($id)
    {
        $id = (int) $id;
        $result = array('drafts' => array(), 'has_value' => array('label' => array(), 'options' => array()));
        if ($id <= 0 || DB::fieldExist('language_value', 'id') !== true || !Config::get('features.language', false)) {
            return $result;
        }

        $rows = DB::table('language_value')
            ->field('language_pack, field, value')
            ->where('module', 'metadata')
            ->where('item_id', $id)
            ->where('field', 'IN', array('label', 'options'))
            ->order('id ASC')
            ->select();

        $default = $this->defaultLanguage();
        $groups = array();
        foreach ((array) $rows as $row) {
            $pack = (string) $row['language_pack'];
            if ($pack === $default || !Check::languagePack($pack)) {
                continue;
            }
            $field = (string) $row['field'];
            $value = (string) $row['value'];
            if ($value === '') {
                continue;
            }
            if (!isset($groups[$pack])) {
                $groups[$pack] = array('language_pack' => $pack, 'fields' => array());
            }
            $groups[$pack]['fields'][] = array('field' => $field, 'value' => $value);
            $result['has_value'][$field][$pack] = true;
        }

        $result['drafts'] = array_values($groups);

        return $result;
    }

    /**
     * 从定义表单数据保存草稿。
     *
     * @param int $id
     * @param array $data
     * @param array $canonicalOptions
     * @return void
     * @throws DomainException
     */
    public function save($id, array $data, array $canonicalOptions)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return;
        }

        $raw = isset($data['definition_language']) && is_array($data['definition_language'])
            ? $data['definition_language']
            : array();
        $default = $this->defaultLanguage();
        $allowedOptions = array_flip(array_map('strval', $canonicalOptions));

        foreach ($raw as $pack => $fields) {
            $pack = (string) $pack;
            if ($pack === $default || !Check::languagePack($pack) || !is_array($fields)) {
                continue;
            }

            if (array_key_exists('label', $fields)) {
                $label = is_scalar($fields['label']) ? trim((string) $fields['label']) : '';
                if ($label !== '' && function_exists('mb_strlen') && mb_strlen($label, 'UTF-8') > 150) {
                    throw new DomainException(lang('metadata_error_label_too_long', '显示名不能超过 150 个字符'));
                }
                $this->store->saveDefinitionField($id, $pack, 'label', $label, 'text');
            }

            if (array_key_exists('options', $fields)) {
                $decoded = array();
                if (is_string($fields['options']) && $fields['options'] !== '') {
                    $decoded = json_decode($fields['options'], true);
                } elseif (is_array($fields['options'])) {
                    $decoded = $fields['options'];
                }
                $map = array();
                if (is_array($decoded)) {
                    foreach ($decoded as $key => $label) {
                        $key = (string) $key;
                        if (!isset($allowedOptions[$key]) || !is_scalar($label)) {
                            continue;
                        }
                        $label = trim((string) $label);
                        if ($label !== '') {
                            $map[$key] = $label;
                        }
                    }
                }
                $this->store->saveDefinitionField($id, $pack, 'options', $map, 'json');
            }
        }

        MetadataService::instance()->clearCache();
    }

    /**
     * 全部启用语言包（不含默认语言）。
     *
     * @return array
     */
    public function languagePacks()
    {
        return $this->store->languagePacks();
    }

    /**
     * @return string
     */
    private function defaultLanguage()
    {
        return (string) Config::get('site.language', 'zh_cn');
    }
}
