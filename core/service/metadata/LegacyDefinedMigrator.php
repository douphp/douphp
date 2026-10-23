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
use Dou\Core\Support\Pinyin;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 旧「自定义属性(defined)」-> 扩展字段(metadata) 一次性迁移。
 *
 * 数据来源与目标：
 * - 来源：dou_config.defined[模块] 的属性名模板 + 内容表 defined 列「名称：值」纯文本
 *         + language_value.field='defined' 的整段译文；
 * - 目标：dou_metadata 中 type=input 的定义（slug 取属性名拼音）+ 内容表 metadata JSON
 *         + language_value.field='metadata' 覆盖 JSON 与 module='metadata'/field='label' 名称翻译。
 *
 * 设计约束：
 * - 由模块升级脚本在 syncStandards() 之前调用，不依赖容器 / lang() / 请求上下文；
 * - 幂等：同 label 的 input 定义复用、已有 metadata 键不覆盖、完成后写 defined_migrated 标记；
 * - fail loud：缺 dou_metadata 表或主表 metadata 列时抛异常（模块版本不落库，升级系统后可重试）。
 */
class LegacyDefinedMigrator
{
    /** @var string 迁移完成标记（dou_config.name） */
    const MARKER = 'defined_migrated';

    /**
     * 迁移指定模块的 defined 数据。
     *
     * @param string $module 模块标识
     * @param string $table 主表名，默认与模块同名
     * @return array 统计：definitions / rows / language_values / language_labels / skipped
     */
    public static function migrate($module, $table = '')
    {
        $stats = array(
            'definitions' => 0,
            'rows' => 0,
            'language_values' => 0,
            'language_labels' => 0,
            'skipped' => false,
        );

        $module = trim((string) $module);
        $table = $table !== '' ? (string) $table : $module;
        if ($module === '' || !DB::tableExist($table) || DB::fieldExist($table, 'defined') !== true) {
            $stats['skipped'] = true;
            return $stats;
        }
        if (self::isMarked($module)) {
            $stats['skipped'] = true;
            return $stats;
        }

        $labels = self::collectLabels($module, $table);
        if (empty($labels)) {
            $stats['skipped'] = true;
            return $stats;
        }

        if (DB::fieldExist('metadata', 'id') !== true) {
            throw new \RuntimeException('扩展字段未迁移：缺少扩展字段定义表 dou_metadata，请先升级主系统后再更新本模块。');
        }
        if (DB::fieldExist($table, 'metadata') !== true) {
            throw new \RuntimeException('扩展字段未迁移：' . $table . ' 表缺少 metadata 列，请先升级主系统后再更新本模块。');
        }

        $definitions = self::ensureDefinitions($module, $labels, $stats);
        self::migrateValues($table, $definitions, $stats);
        self::migrateLanguage($module, $definitions, $stats);
        self::mark($module);
        self::writeLog($module, $stats);

        return $stats;
    }

    /**
     * 属性名清单：config.defined[模块] 名单在前，内容行中首次出现的名称在后。
     *
     * @param string $module
     * @param string $table
     * @return array
     */
    private static function collectLabels($module, $table)
    {
        $labels = array();
        $seen = array();

        foreach (self::configLabels($module) as $label) {
            if (!isset($seen[$label])) {
                $seen[$label] = true;
                $labels[] = $label;
            }
        }

        $query = DB::query("SELECT `defined` FROM " . DB::tableName($table) . " WHERE `defined` <> ''");
        while ($row = DB::fetchArray($query)) {
            foreach (self::parsePairs($row['defined']) as $pair) {
                if ($pair['label'] === '' || isset($seen[$pair['label']])) {
                    continue;
                }
                $seen[$pair['label']] = true;
                $labels[] = $pair['label'];
            }
        }

        return $labels;
    }

    /**
     * config.defined 中该模块的属性名模板（逗号分隔）。
     *
     * @param string $module
     * @return array
     */
    private static function configLabels($module)
    {
        if (!DB::tableExist('config') || DB::fieldExist('config', 'name') !== true) {
            return array();
        }

        $raw = DB::getOne("SELECT `value` FROM " . DB::tableName('config') . " WHERE `name` = 'defined'");
        $map = (is_string($raw) && $raw !== '') ? @unserialize($raw) : array();
        if (!is_array($map) || !isset($map[$module]) || !is_scalar($map[$module])) {
            return array();
        }

        $labels = array();
        foreach (preg_split('/[,，]/u', (string) $map[$module]) as $label) {
            $label = trim($label);
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * 解析「名称：值」列表（严格复刻旧 Util::parseDefinedPairs）。
     *
     * 含逗号按逗号切分，否则按换行切分；半角冒号归一为全角后取前两段。
     *
     * @param string $text
     * @return array 每项 array('label' => string, 'value' => string)
     */
    private static function parsePairs($text)
    {
        $pairs = array();
        $text = (string) $text;
        if (trim($text) === '') {
            return $pairs;
        }

        if (strpos($text, ',') !== false) {
            $rows = explode(',', $text);
        } else {
            $rows = explode("\n", str_replace(array("\r\n", "\r"), "\n", $text));
        }

        foreach ($rows as $row) {
            $row = str_replace(':', '：', $row);
            $parts = explode('：', $row);
            if (!isset($parts[1])) {
                continue;
            }
            $pairs[] = array(
                'label' => trim($parts[0]),
                'value' => trim($parts[1]),
            );
        }

        return $pairs;
    }

    /**
     * 确保每个属性名都有可用的 input 定义；同 label 的既有 input 定义复用其 slug。
     *
     * @param string $module
     * @param array $labels
     * @param array $stats
     * @return array labelToSlug / slugToId / slugToLabel / order
     */
    private static function ensureDefinitions($module, array $labels, array &$stats)
    {
        $rows = (array) DB::table('metadata')->where('module', $module)->order('sort ASC, id ASC')->select();
        $bySlug = array();
        $byLabel = array();
        $count = 0;
        foreach ($rows as $row) {
            $row = (array) $row;
            $count++;
            $slug = (string) $row['slug'];
            $label = (string) $row['label'];
            $bySlug[$slug] = $row;
            if ((string) $row['type'] === 'input' && $label !== '' && !isset($byLabel[$label])) {
                $byLabel[$label] = $row;
            }
        }

        $definitions = array(
            'labelToSlug' => array(),
            'slugToId' => array(),
            'slugToLabel' => array(),
            'order' => array(),
        );

        foreach ($labels as $label) {
            if (isset($definitions['labelToSlug'][$label])) {
                continue;
            }

            if (isset($byLabel[$label])) {
                $definition = $byLabel[$label];
            } else {
                if ($count >= MetadataSchema::MAX_FIELDS_PER_MODULE) {
                    throw new \RuntimeException('扩展字段未迁移：' . $module . ' 模块的扩展字段数量已达上限（'
                        . MetadataSchema::MAX_FIELDS_PER_MODULE . '），请先在后台清理扩展字段后重试。');
                }

                $slug = self::uniqueSlug($label, $bySlug);
                $row = array(
                    'module' => $module,
                    'slug' => $slug,
                    'label' => $label,
                    'type' => 'input',
                    'options' => '',
                    'extensions' => '',
                    'max_kb' => 0,
                    'required' => 0,
                    'limit' => 0,
                    'tip' => '',
                    'sort' => 50 + $count,
                    'status' => 1,
                );
                $id = DB::table('metadata')->insert($row);
                if (!$id) {
                    throw new \RuntimeException('扩展字段未迁移：' . $module . ' 模块写入字段定义失败（' . $label . '）。');
                }
                $row['id'] = (int) $id;
                $bySlug[$slug] = $row;
                $byLabel[$label] = $row;
                $count++;
                $stats['definitions']++;
                $definition = $row;
            }

            $slug = (string) $definition['slug'];
            $definitions['labelToSlug'][$label] = $slug;
            $definitions['slugToId'][$slug] = (int) $definition['id'];
            $definitions['slugToLabel'][$slug] = (string) $definition['label'];
            $definitions['order'][] = $slug;
        }

        return $definitions;
    }

    /**
     * 模块内唯一 slug：拼音基名冲突时追加 _2、_3……（总长不超过 30）。
     *
     * @param string $label
     * @param array $taken slug => row
     * @return string
     */
    private static function uniqueSlug($label, array $taken)
    {
        $base = Pinyin::slug($label);
        if ($base === '') {
            $base = 'field';
        }

        $slug = $base;
        $index = 2;
        while (isset($taken[$slug]) || !preg_match('/^[a-z][a-z0-9_]{0,29}$/', $slug)) {
            $suffix = '_' . $index;
            $slug = rtrim(substr($base, 0, 30 - strlen($suffix)), '_') . $suffix;
            $index++;
            if ($index > 1000) {
                break;
            }
        }

        return preg_match('/^[a-z][a-z0-9_]{0,29}$/', $slug) ? $slug : 'field';
    }

    /**
     * 逐行把 defined 文本并入 metadata JSON（已有键不覆盖）。
     *
     * @param string $table
     * @param array $definitions
     * @param array $stats
     * @return void
     */
    private static function migrateValues($table, array $definitions, array &$stats)
    {
        if (empty($definitions['labelToSlug'])) {
            return;
        }

        $query = DB::query("SELECT `id`, `defined`, `metadata` FROM " . DB::tableName($table) . " WHERE `defined` <> ''");
        while ($row = DB::fetchArray($query)) {
            $values = array();
            foreach (self::parsePairs($row['defined']) as $pair) {
                if ($pair['value'] === '' || !isset($definitions['labelToSlug'][$pair['label']])) {
                    continue;
                }
                $slug = $definitions['labelToSlug'][$pair['label']];
                if (!isset($values[$slug])) {
                    $values[$slug] = $pair['value'];
                }
            }
            if (empty($values)) {
                continue;
            }

            $existing = self::decodeMap(isset($row['metadata']) ? $row['metadata'] : '');
            $merged = $values;
            foreach ($existing as $key => $value) {
                $merged[(string) $key] = $value;
            }
            if ($merged == $existing) {
                continue;
            }

            $json = self::encodeMap($merged);
            if ($json === '') {
                continue;
            }
            if (DB::query("UPDATE " . DB::tableName($table) . " SET `metadata` = '" . DB::escapeString($json)
                . "' WHERE `id` = '" . (int) $row['id'] . "'") === false) {
                throw new \RuntimeException('扩展字段未迁移：写入 ' . $table . ' 表的 metadata 失败（'
                    . DB::getLastError() . '），请检查数据库结构与权限后重试。');
            }
            $stats['rows']++;
        }
    }

    /**
     * 迁移多语言：defined 译文 -> metadata 覆盖 JSON + 定义名翻译。
     *
     * @param string $module
     * @param array $definitions
     * @param array $stats
     * @return void
     */
    private static function migrateLanguage($module, array $definitions, array &$stats)
    {
        if (empty($definitions['order']) || DB::fieldExist('language_value', 'id') !== true) {
            return;
        }

        $defaultPack = self::defaultLanguage();

        // 已有覆盖行：pack|item => row
        $metadataRows = array();
        $query = DB::query("SELECT `id`, `language_pack`, `item_id`, `value` FROM " . DB::tableName('language_value')
            . " WHERE `module` = '" . DB::escapeString($module) . "' AND `field` = 'metadata'");
        while ($row = DB::fetchArray($query)) {
            $metadataRows[(string) $row['language_pack'] . '|' . (int) $row['item_id']] = $row;
        }

        // 已有定义名翻译：pack|definitionId => true
        $labelRows = array();
        $query = DB::query("SELECT `language_pack`, `item_id` FROM " . DB::tableName('language_value')
            . " WHERE `module` = 'metadata' AND `field` = 'label'");
        while ($row = DB::fetchArray($query)) {
            $labelRows[(string) $row['language_pack'] . '|' . (int) $row['item_id']] = true;
        }

        $query = DB::query("SELECT `language_pack`, `item_id`, `value` FROM " . DB::tableName('language_value')
            . " WHERE `module` = '" . DB::escapeString($module) . "' AND `field` = 'defined'"
            . " ORDER BY `language_pack` ASC, `item_id` ASC");
        while ($row = DB::fetchArray($query)) {
            $pack = (string) $row['language_pack'];
            $itemId = (int) $row['item_id'];
            if ($pack === '' || $itemId <= 0 || ($defaultPack !== '' && $pack === $defaultPack)) {
                continue;
            }

            // 名称优先精确匹配定义 label，否则按位置映射定义顺序
            $values = array();
            $labels = array();
            $index = 0;
            foreach (self::parsePairs($row['value']) as $pair) {
                $slug = '';
                if (isset($definitions['labelToSlug'][$pair['label']])) {
                    $slug = $definitions['labelToSlug'][$pair['label']];
                } elseif (isset($definitions['order'][$index])) {
                    $slug = $definitions['order'][$index];
                }
                $index++;

                if ($slug === '') {
                    continue;
                }
                if ($pair['value'] !== '' && !isset($values[$slug])) {
                    $values[$slug] = $pair['value'];
                }
                $defaultLabel = isset($definitions['slugToLabel'][$slug]) ? $definitions['slugToLabel'][$slug] : '';
                if ($pair['label'] !== '' && !isset($labels[$slug]) && $pair['label'] !== $defaultLabel) {
                    $labels[$slug] = $pair['label'];
                }
            }

            if (!empty($values)) {
                $key = $pack . '|' . $itemId;
                $existingRow = isset($metadataRows[$key]) ? $metadataRows[$key] : null;
                $existing = $existingRow ? self::decodeMap($existingRow['value']) : array();
                $merged = $values;
                foreach ($existing as $k => $v) {
                    $merged[(string) $k] = $v;
                }

                if ($existingRow === null || $merged != $existing) {
                    $json = self::encodeMap($merged);
                    if ($json !== '') {
                        if ($existingRow !== null) {
                            $ok = DB::query("UPDATE " . DB::tableName('language_value') . " SET `value` = '" . DB::escapeString($json)
                                . "', `type` = 'json' WHERE `id` = '" . (int) $existingRow['id'] . "'");
                            $storedId = (int) $existingRow['id'];
                        } else {
                            $ok = DB::query("INSERT INTO " . DB::tableName('language_value')
                                . " (`language_pack`, `module`, `item_id`, `field`, `value`, `type`, `created_at`)"
                                . " VALUES ('" . DB::escapeString($pack) . "', '" . DB::escapeString($module) . "', '$itemId', 'metadata', '"
                                . DB::escapeString($json) . "', 'json', '" . date('Y-m-d H:i:s') . "')");
                            $storedId = (int) DB::insertId();
                        }
                        if ($ok === false) {
                            throw new \RuntimeException('扩展字段未迁移：写入 ' . $module . ' 的多语言覆盖值失败（'
                                . DB::getLastError() . '），请先完成主系统升级后重试。');
                        }
                        $stats['language_values']++;
                        $metadataRows[$key] = array('id' => $storedId, 'value' => $json);
                    }
                }
            }

            foreach ($labels as $slug => $label) {
                if (!isset($definitions['slugToId'][$slug])) {
                    continue;
                }
                $definitionId = (int) $definitions['slugToId'][$slug];
                $labelKey = $pack . '|' . $definitionId;
                if (isset($labelRows[$labelKey])) {
                    continue;
                }
                if (DB::query("INSERT INTO " . DB::tableName('language_value')
                    . " (`language_pack`, `module`, `item_id`, `field`, `value`, `type`, `created_at`)"
                    . " VALUES ('" . DB::escapeString($pack) . "', 'metadata', '$definitionId', 'label', '"
                    . DB::escapeString($label) . "', 'text', '" . date('Y-m-d H:i:s') . "')") === false) {
                    throw new \RuntimeException('扩展字段未迁移：写入 ' . $module . ' 的字段名翻译失败（'
                        . DB::getLastError() . '），请先完成主系统升级后重试。');
                }
                $labelRows[$labelKey] = true;
                $stats['language_labels']++;
            }
        }
    }

    /**
     * 默认语言包（config.language）；读不到时返回空串（不做跳过）。
     *
     * @return string
     */
    private static function defaultLanguage()
    {
        if (!DB::tableExist('config')) {
            return '';
        }
        $value = DB::getOne("SELECT `value` FROM " . DB::tableName('config') . " WHERE `name` = 'language'");

        return is_string($value) ? $value : '';
    }

    /**
     * 该模块是否已完成迁移。
     *
     * @param string $module
     * @return bool
     */
    private static function isMarked($module)
    {
        if (!DB::tableExist('config') || DB::fieldExist('config', 'name') !== true) {
            return false;
        }

        $map = self::markerMap();

        return !empty($map[$module]);
    }

    /**
     * 迁移标记 map（config.defined_migrated）。
     *
     * @return array
     */
    private static function markerMap()
    {
        $raw = DB::getOne("SELECT `value` FROM " . DB::tableName('config') . " WHERE `name` = '" . self::MARKER . "'");
        $map = (is_string($raw) && $raw !== '') ? @unserialize($raw) : array();

        return is_array($map) ? $map : array();
    }

    /**
     * 写入迁移完成标记。
     *
     * @param string $module
     * @return void
     */
    private static function mark($module)
    {
        if (!DB::tableExist('config') || DB::fieldExist('config', 'name') !== true) {
            return;
        }

        $map = self::markerMap();
        $map[$module] = 1;
        $value = serialize($map);
        $exists = DB::getOne("SELECT `id` FROM " . DB::tableName('config') . " WHERE `name` = '" . self::MARKER . "'");
        if ($exists) {
            $ok = DB::query("UPDATE " . DB::tableName('config') . " SET `value` = '" . DB::escapeString($value)
                . "' WHERE `name` = '" . self::MARKER . "'");
        } else {
            $ok = DB::query("INSERT INTO " . DB::tableName('config') . " (`name`, `value`, `type`, `box`, `tab`)"
                . " VALUES ('" . self::MARKER . "', '" . DB::escapeString($value) . "', 'hidden', '', '')");
        }
        if ($ok === false) {
            throw new \RuntimeException('扩展字段未迁移：写入 ' . $module . ' 的迁移标记失败（'
                . DB::getLastError() . '），请检查 config 表结构后重试。');
        }
    }

    /**
     * 追加迁移日志（storage/log/metadata_migration.log）。
     *
     * @param string $module
     * @param array $stats
     * @return void
     */
    private static function writeLog($module, array $stats)
    {
        if (!defined('ROOT_PATH')) {
            return;
        }
        $dir = ROOT_PATH . 'storage/log/';
        if (!is_dir($dir)) {
            return;
        }

        $line = date('Y-m-d H:i:s') . ' defined->metadata module=' . $module
            . ' definitions=' . (int) $stats['definitions']
            . ' rows=' . (int) $stats['rows']
            . ' language_values=' . (int) $stats['language_values']
            . ' language_labels=' . (int) $stats['language_labels'] . PHP_EOL;
        @file_put_contents($dir . 'metadata_migration.log', $line, FILE_APPEND);
    }

    /**
     * @param mixed $json
     * @return array
     */
    private static function decodeMap($json)
    {
        if (is_array($json)) {
            return $json;
        }
        if (!is_string($json) || trim($json) === '') {
            return array();
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @param array $map
     * @return string
     */
    private static function encodeMap(array $map)
    {
        if (empty($map)) {
            return '';
        }
        $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '';
    }
}
