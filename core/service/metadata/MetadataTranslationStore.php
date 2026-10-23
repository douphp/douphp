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
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 扩展字段的多语言覆盖值存取。
 *
 * 每个语言一行：field='metadata'、type='json'、value=覆盖 map JSON。
 * 覆盖 map 只存显式覆盖的 slug；键不存在 = 继承默认语言。
 */
class MetadataTranslationStore extends BaseService
{
    /**
     * @return bool
     */
    public function isAvailable()
    {
        return !empty(Config::get('features.language', false))
            && DB::fieldExist('language_value', 'id') === true;
    }

    /**
     * @param string $module
     * @param int $itemId
     * @return array language_pack => map
     */
    public function all($module, $itemId)
    {
        if (!$this->isAvailable() || (int) $itemId <= 0) {
            return array();
        }

        $rows = DB::table('language_value')
            ->field('language_pack, value')
            ->where('module', (string) $module)
            ->where('item_id', (int) $itemId)
            ->where('field', 'metadata')
            ->select();

        $maps = array();
        foreach ((array) $rows as $row) {
            $maps[(string) $row['language_pack']] = MetadataValues::decode($row['value']);
        }

        return $maps;
    }

    /**
     * @param string $module
     * @param int $itemId
     * @param string $languagePack
     * @return array
     */
    public function get($module, $itemId, $languagePack)
    {
        if (!$this->isAvailable() || (int) $itemId <= 0) {
            return array();
        }

        $value = DB::table('language_value')
            ->where('module', (string) $module)
            ->where('item_id', (int) $itemId)
            ->where('field', 'metadata')
            ->where('language_pack', (string) $languagePack)
            ->value('value');

        return MetadataValues::decode($value);
    }

    /**
     * 保存指定语言的覆盖 map；空 map 删除该语言行。
     *
     * 不走 LanguageValueService::saveValue()：后者拒绝空值且查询条件含 type，
     * 与本表唯一键（不含 type）不一致；这里按 (pack,module,item,field) 直接替换。
     *
     * @param string $module
     * @param int $itemId
     * @param array $maps language_pack => map
     * @param string $defaultLang
     * @return void
     */
    public function save($module, $itemId, array $maps, $defaultLang = '')
    {
        if (!$this->isAvailable() || (int) $itemId <= 0) {
            return;
        }

        $module = (string) $module;
        $itemId = (int) $itemId;
        $defaultLang = $defaultLang !== '' ? $defaultLang : $this->defaultLanguage();

        foreach ($maps as $languagePack => $map) {
            $languagePack = (string) $languagePack;
            if ($languagePack === '' || $languagePack === $defaultLang) {
                continue;
            }

            $this->deleteOne($module, $itemId, $languagePack);
            $json = MetadataValues::encode(is_array($map) ? $map : array());
            if ($json === '') {
                continue;
            }

            $insertId = DB::table('language_value')->insert(array(
                'language_pack' => $languagePack,
                'module' => $module,
                'item_id' => $itemId,
                'field' => 'metadata',
                'value' => $json,
                'type' => 'json',
                'created_at' => date('Y-m-d H:i:s'),
            ));
            if (!$insertId) {
                Log::warning('metadata translation insert failed', array(
                    'module' => $module,
                    'item_id' => $itemId,
                    'language_pack' => $languagePack,
                    'error' => DB::getFacadeRoot()->getLastError(),
                    'sql' => DB::getFacadeRoot()->lastSql(),
                ));
                throw new DomainException(lang('metadata_error_save_failed', '扩展字段保存失败'));
            }
        }
    }

    /**
     * 删除记录的全部字段翻译行。
     *
     * @param string $module
     * @param int $itemId
     * @return void
     */
    public function delete($module, $itemId)
    {
        if (DB::fieldExist('language_value', 'id') !== true) {
            return;
        }

        DB::table('language_value')
            ->where('module', (string) $module)
            ->where('item_id', (int) $itemId)
            ->where('field', 'metadata')
            ->delete();
    }

    /**
     * 读取一批定义的文案翻译。
     *
     * @param array $ids 定义 id
     * @param string $pack
     * @return array id => ['label' => string, 'options' => array]
     */
    public function definitionTranslations(array $ids, $pack)
    {
        if (!$this->isAvailable() || empty($ids) || (string) $pack === '') {
            return array();
        }

        $rows = DB::table('language_value')
            ->field('item_id, field, value')
            ->where('module', 'metadata')
            ->where('item_id', 'IN', array_values($ids))
            ->where('language_pack', (string) $pack)
            ->where('field', 'IN', array('label', 'options'))
            ->select();

        $out = array();
        foreach ((array) $rows as $row) {
            $id = (int) $row['item_id'];
            if (!isset($out[$id])) {
                $out[$id] = array('label' => '', 'options' => array());
            }
            if ($row['field'] === 'label') {
                $out[$id]['label'] = (string) $row['value'];
            } elseif ($row['field'] === 'options') {
                $decoded = json_decode((string) $row['value'], true);
                $out[$id]['options'] = is_array($decoded) ? $decoded : array();
            }
        }

        return $out;
    }

    /**
     * 读取单个定义字段的翻译。
     *
     * @param int $id
     * @param string $pack
     * @param string $field label|options
     * @return string|array
     */
    public function definitionGet($id, $pack, $field)
    {
        $translations = $this->definitionTranslations(array((int) $id), $pack);
        $id = (int) $id;
        if (!isset($translations[$id])) {
            return $field === 'options' ? array() : '';
        }

        return $field === 'options'
            ? (isset($translations[$id]['options']) ? $translations[$id]['options'] : array())
            : (isset($translations[$id]['label']) ? $translations[$id]['label'] : '');
    }

    /**
     * 保存单个定义字段的翻译；空值删除。
     *
     * @param int $id
     * @param string $pack
     * @param string $field label|options
     * @param mixed $value
     * @param string $type text|json
     * @return void
     */
    public function saveDefinitionField($id, $pack, $field, $value, $type = 'text')
    {
        if (!$this->isAvailable() || (int) $id <= 0 || (string) $pack === '') {
            return;
        }

        $id = (int) $id;
        $pack = (string) $pack;
        $field = (string) $field;
        $stored = $value;
        if ($type === 'json') {
            $stored = MetadataValues::encode(is_array($value) ? $value : array());
        }

        $this->deleteDefinitionField($id, $pack, $field);

        if ($stored === '' || $stored === '{}' || $stored === '[]') {
            return;
        }

        $insertId = DB::table('language_value')->insert(array(
            'language_pack' => $pack,
            'module' => 'metadata',
            'item_id' => $id,
            'field' => $field,
            'value' => $stored,
            'type' => $type,
            'created_at' => date('Y-m-d H:i:s'),
        ));
        if (!$insertId) {
            Log::warning('metadata definition translation insert failed', array(
                'id' => $id,
                'language_pack' => $pack,
                'field' => $field,
                'error' => DB::getFacadeRoot()->getLastError(),
            ));
            throw new DomainException(lang('metadata_error_save_failed', '扩展字段保存失败'));
        }
    }

    /**
     * 删除单个定义字段翻译。
     *
     * @param int $id
     * @param string $pack
     * @param string $field
     * @return void
     */
    public function deleteDefinitionField($id, $pack, $field)
    {
        if (DB::fieldExist('language_value', 'id') !== true) {
            return;
        }

        DB::table('language_value')
            ->where('module', 'metadata')
            ->where('item_id', (int) $id)
            ->where('language_pack', (string) $pack)
            ->where('field', (string) $field)
            ->delete();
    }

    /**
     * 删除定义的全部文案翻译。
     *
     * @param int $id
     * @return void
     */
    public function deleteDefinition($id)
    {
        $this->deleteDefinitions(array((int) $id));
    }

    /**
     * 批量删除定义文案翻译。
     *
     * @param array $ids
     * @return void
     */
    public function deleteDefinitions(array $ids)
    {
        if (DB::fieldExist('language_value', 'id') !== true) {
            return;
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return;
        }

        DB::table('language_value')
            ->where('module', 'metadata')
            ->where('item_id', 'IN', $ids)
            ->where('field', 'IN', array('label', 'options'))
            ->delete();
    }

    /**
     * 清理不再存在的选项翻译 key。
     *
     * @param int $id
     * @param array $canonicalOptions
     * @return void
     */
    public function pruneOptionLabels($id, array $canonicalOptions)
    {
        if (!$this->isAvailable() || (int) $id <= 0) {
            return;
        }

        $rows = DB::table('language_value')
            ->field('language_pack, value')
            ->where('module', 'metadata')
            ->where('item_id', (int) $id)
            ->where('field', 'options')
            ->select();
        $allowed = array_flip(array_map('strval', $canonicalOptions));

        foreach ((array) $rows as $row) {
            $decoded = json_decode((string) $row['value'], true);
            if (!is_array($decoded)) {
                $this->deleteDefinitionField((int) $id, (string) $row['language_pack'], 'options');
                continue;
            }
            $kept = array();
            foreach ($decoded as $key => $label) {
                if (isset($allowed[(string) $key]) && $label !== '') {
                    $kept[(string) $key] = (string) $label;
                }
            }
            if (empty($kept)) {
                $this->deleteDefinitionField((int) $id, (string) $row['language_pack'], 'options');
            } else {
                $this->saveDefinitionField((int) $id, (string) $row['language_pack'], 'options', $kept, 'json');
            }
        }
    }

    /**
     * 模块卸载时清理。
     *
     * @param string $module
     * @return void
     */
    public function deleteByModule($module)
    {
        if (DB::fieldExist('language_value', 'id') !== true) {
            return;
        }

        DB::table('language_value')
            ->where('module', (string) $module)
            ->where('field', 'metadata')
            ->delete();
    }

    /**
     * @return string
     */
    public function defaultLanguage()
    {
        return (string) Config::get('site.language', 'zh_cn');
    }

    /**
     * 启用语言包（不含默认语言）。
     *
     * @return array
     */
    public function languagePacks()
    {
        if (!$this->isAvailable()) {
            return array();
        }

        $default = $this->defaultLanguage();
        $rows = DB::table('language')
            ->field('name, language_pack')
            ->order('sort ASC, id DESC')
            ->select();

        $packs = array();
        foreach ((array) $rows as $row) {
            if ($row['language_pack'] === $default) {
                continue;
            }
            $packs[] = array(
                'name' => (string) $row['name'],
                'language_pack' => (string) $row['language_pack'],
            );
        }

        return $packs;
    }

    /**
     * @param string $module
     * @param int $itemId
     * @param string $languagePack
     * @return void
     */
    private function deleteOne($module, $itemId, $languagePack)
    {
        DB::table('language_value')
            ->where('module', $module)
            ->where('item_id', $itemId)
            ->where('field', 'metadata')
            ->where('language_pack', $languagePack)
            ->delete();
    }
}
