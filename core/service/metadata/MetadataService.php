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
 * 扩展字段定义 CRUD 与读取。
 *
 * 定义表 dou_metadata 未升级/未安装时统一返回空 schema，调用方无需再做存在性判断。
 */
class MetadataService extends BaseService
{
    /** @var self|null */
    private static $instance = null;

    /** @var array 请求内 schema 缓存：module|includeDisabled => slug => definition */
    private $schemaCache = array();

    /**
     * @return self
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * 定义表是否就绪（系统升级后才会存在）。
     *
     * @return bool
     */
    public function isReady()
    {
        return DB::fieldExist('metadata', 'id') === true;
    }

    /**
     * 某模块启用的字段定义，按 sort ASC, id ASC。
     *
     * @param string $module
     * @param bool $includeDisabled
     * @return array slug => definition
     */
    public function schema($module, $includeDisabled = false)
    {
        $module = (string) $module;
        if (!$this->isReady() || !MetadataModules::exists($module)) {
            return array();
        }

        $pack = $this->localePack();
        $cacheKey = $module . '|' . ($includeDisabled ? '1' : '0') . '|' . $pack;
        if (isset($this->schemaCache[$cacheKey])) {
            return $this->schemaCache[$cacheKey];
        }

        $query = DB::table('metadata')->where('module', $module);
        if (!$includeDisabled) {
            $query->where('status', 1);
        }
        $rows = $query->order('sort ASC, id ASC')->select();
        $schema = MetadataSchema::normalizeDefinitions((array) $rows);
        $schema = $this->mergeDefinitionTranslations($schema, $pack);
        $this->schemaCache[$cacheKey] = $schema;

        return $schema;
    }

    /**
     * @param string $module
     * @return array
     */
    public function all($module)
    {
        return $this->schema($module, true);
    }

    /**
     * @param int $id
     * @return array|null
     */
    public function find($id)
    {
        if (!$this->isReady() || (int) $id <= 0) {
            return null;
        }
        $row = DB::table('metadata')->where('id', (int) $id)->find();

        return $row ? MetadataSchema::normalizeDefinition((array) $row) : null;
    }

    /**
     * @param string $module
     * @param string $slug
     * @return array|null
     */
    public function findBySlug($module, $slug)
    {
        if (!$this->isReady()) {
            return null;
        }
        $row = DB::table('metadata')
            ->where('module', (string) $module)
            ->where('slug', (string) $slug)
            ->find();

        return $row ? MetadataSchema::normalizeDefinition((array) $row) : null;
    }

    /**
     * 新增定义。
     *
     * @param array $data
     * @return int
     * @throws DomainException
     */
    public function create(array $data)
    {
        if (!$this->isReady()) {
            throw new DomainException(lang('metadata_error_not_ready', '扩展字段定义表尚未就绪，请先升级系统'));
        }

        $module = isset($data['module']) ? (string) $data['module'] : '';
        $slug = isset($data['slug']) ? trim((string) $data['slug']) : '';
        $errors = MetadataSchema::validateDefinition($data);
        if (!empty($errors)) {
            throw new DomainException(implode('；', $errors));
        }
        if ($this->findBySlug($module, $slug)) {
            throw new DomainException(lang('metadata_error_slug_exists', '该模块下字段标识已存在'));
        }
        $count = count((array) DB::table('metadata')->where('module', $module)->select());
        if ($count >= MetadataSchema::MAX_FIELDS_PER_MODULE) {
            throw new DomainException(lang('metadata_error_limit_reached', '单个模块的扩展字段数量已达上限'));
        }

        $row = $this->buildRow($data);
        $id = DB::table('metadata')->insert($row);
        if (!$id) {
            Log::warning('metadata create insert failed', array(
                'row' => $row,
                'error' => DB::getFacadeRoot()->getLastError(),
                'sql' => DB::getFacadeRoot()->lastSql(),
            ));
            throw new DomainException(lang('metadata_error_save_failed', '扩展字段保存失败'));
        }
        $this->clearCache($module);

        return (int) $id;
    }

    /**
     * 更新定义；slug/type 创建后不可修改。
     *
     * @param int $id
     * @param array $data
     * @return void
     * @throws DomainException
     */
    public function update($id, array $data)
    {
        $existing = $this->find($id);
        if (!$existing) {
            throw new DomainException(lang('metadata_error_not_found', '扩展字段不存在'));
        }

        $data['module'] = $existing['module'];
        $data['slug'] = $existing['slug'];
        $data['type'] = $existing['type'];
        $errors = MetadataSchema::validateDefinition($data, $existing);
        if (!empty($errors)) {
            throw new DomainException(implode('；', $errors));
        }

        $result = DB::table('metadata')->where('id', (int) $id)->data($this->buildRow($data))->update();
        if ($result === false) {
            Log::warning('metadata update failed', array('id' => (int) $id, 'error' => DB::getFacadeRoot()->getLastError()));
            throw new DomainException(lang('metadata_error_save_failed', '扩展字段保存失败'));
        }

        if (array_key_exists('options', $data) && in_array($existing['type'], array('select', 'radio', 'checkbox'), true)) {
            $store = new MetadataTranslationStore();
            $store->pruneOptionLabels((int) $id, MetadataSchema::parseOptions($data['options']));
        }
        $this->clearCache($existing['module']);
    }

    /**
     * 删除定义；已存值与附件保留（重建同 slug 定义即可恢复展示）。
     *
     * @param int $id
     * @return void
     * @throws DomainException
     */
    public function delete($id)
    {
        $existing = $this->find($id);
        if (!$existing) {
            throw new DomainException(lang('metadata_error_not_found', '扩展字段不存在'));
        }
        $result = DB::table('metadata')->where('id', (int) $id)->delete();
        if ($result === false) {
            Log::warning('metadata delete failed', array('id' => (int) $id, 'error' => DB::getFacadeRoot()->getLastError()));
            throw new DomainException(lang('metadata_error_save_failed', '扩展字段保存失败'));
        }
        $store = new MetadataTranslationStore();
        $store->deleteDefinition((int) $id);
        $this->clearCache($existing['module']);
    }

    /**
     * 模块卸载时清理定义。
     *
     * @param string $module
     * @return void
     */
    public function deleteByModule($module)
    {
        if (!$this->isReady()) {
            return;
        }
        $rows = DB::table('metadata')->field('id')->where('module', (string) $module)->select();
        $ids = array();
        foreach ((array) $rows as $row) {
            $ids[] = (int) $row['id'];
        }
        DB::table('metadata')->where('module', (string) $module)->delete();
        if (!empty($ids)) {
            $store = new MetadataTranslationStore();
            $store->deleteDefinitions($ids);
        }
        $this->clearCache((string) $module);
    }

    /**
     * 当前语言包；后台无 locale 切换时回退默认语言。
     *
     * @return string
     */
    private function localePack()
    {
        if (function_exists('locale')) {
            $locale = locale();
            if (is_object($locale) && method_exists($locale, 'isActive') && $locale->isActive()) {
                return (string) $locale->pack();
            }
        }

        return (string) Config::get('site.language', 'zh_cn');
    }

    /**
     * 合并定义 label/options 翻译；options 规范值不变，另给显示文案 map。
     *
     * @param array $schema
     * @param string $pack
     * @return array
     */
    private function mergeDefinitionTranslations(array $schema, $pack)
    {
        $default = (string) Config::get('site.language', 'zh_cn');
        $translations = array();

        if ($pack !== $default && !empty($schema)) {
            $ids = array();
            foreach ($schema as $definition) {
                $ids[] = (int) $definition['id'];
            }
            $store = new MetadataTranslationStore();
            $translations = $store->definitionTranslations($ids, $pack);
        }

        foreach ($schema as $slug => $definition) {
            $labels = array();
            foreach ((array) $definition['options'] as $option) {
                $labels[(string) $option] = (string) $option;
            }

            $id = (int) $definition['id'];
            if (isset($translations[$id])) {
                if (!empty($translations[$id]['label'])) {
                    $schema[$slug]['label'] = (string) $translations[$id]['label'];
                }
                if (!empty($translations[$id]['options'])) {
                    foreach ($translations[$id]['options'] as $option => $label) {
                        if (isset($labels[(string) $option]) && $label !== '') {
                            $labels[(string) $option] = (string) $label;
                        }
                    }
                }
            }

            $schema[$slug]['options_labels'] = $labels;
        }

        return $schema;
    }

    /**
     * @param string $module
     * @return void
     */
    public function clearCache($module = '')
    {
        if ($module === '') {
            $this->schemaCache = array();
            return;
        }
        $this->schemaCache = array();
    }

    /**
     * @param array $data
     * @return array
     */
    private function buildRow(array $data)
    {
        $type = isset($data['type']) ? (string) $data['type'] : 'input';
        $extensions = isset($data['extensions']) ? strtolower(trim((string) $data['extensions'])) : '';
        if ($type === 'file') {
            $checked = MetadataSchema::validateFileExtensions($extensions);
            $extensions = $checked['ok'] ? $checked['extensions'] : '';
        } else {
            $extensions = '';
        }

        return array(
            'module' => isset($data['module']) ? (string) $data['module'] : '',
            'slug' => isset($data['slug']) ? trim((string) $data['slug']) : '',
            'label' => isset($data['label']) ? trim((string) $data['label']) : '',
            'type' => $type,
            'options' => implode("\n", MetadataSchema::parseOptions(isset($data['options']) ? $data['options'] : '')),
            'extensions' => $extensions,
            'max_kb' => isset($data['max_kb']) ? max(0, (int) $data['max_kb']) : 0,
            'required' => !empty($data['required']) ? 1 : 0,
            'limit' => isset($data['limit']) ? max(0, (int) $data['limit']) : 0,
            'tip' => isset($data['tip']) ? trim((string) $data['tip']) : '',
            'sort' => isset($data['sort']) ? (int) $data['sort'] : 50,
            'status' => isset($data['status']) ? ((int) $data['status'] === 1 ? 1 : 0) : 1,
        );
    }
}
