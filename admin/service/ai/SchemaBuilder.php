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

namespace Dou\Admin\Service\Ai;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 应用的模块字段与输出契约（JSON Schema）草稿生成。
 *
 * 选定内容模块后：取表字段集（SHOW COLUMNS ∩ 排除系统列）+ 语言包中文名，
 * 自动生成应用的 field 候选与输出 JSON Schema，供生成时按已选字段现算。
 */
class SchemaBuilder extends BaseService
{
    /**
     * 不参与 AI 生成的系统列（主键 / 统计 / 时间戳 / 关联媒介列）。
     *
     * @var array
     */
    private static $systemColumns = array(
        'id', 'category_id', 'click', 'comment_count', 'favorites_count', 'sales_count',
        'created_at', 'last_update', 'created_at', 'updated_at', 'created_by',
        'operator_type', 'operator_id', 'sales',
    );

    /**
     * AI 不可生成字段黑名单（精确名）：媒体资源 / 商业事实数据 / 凭据，文本模型无法可靠产出。
     *
     * @var array
     */
    private static $aiBlockedColumns = array(
        'image', 'thumb', 'gallery', 'file', 'video', 'audio', 'attachment',
        'avatar', 'logo', 'banner', 'cover',
        'price', 'market_price', 'sale_price', 'cost_price',
        'stock', 'sku', 'sn', 'barcode', 'weight', 'volume',
        'password', 'token', 'salt',
    );

    /**
     * AI 不可生成字段黑名单（前后缀模式）。
     *
     * @var array
     */
    private static $aiBlockedSuffixes = array(
        '_image', '_img', '_thumb', '_gallery', '_file', '_video', '_audio',
        '_price', '_stock', '_sku', '_sn', '_id',
    );

    /**
     * 可挂载模块列表（含 column_module 的 _category 变体），供应用表单下拉。
     *
     * link_ai 中已安装的模块可挂载；column_module 自动派生 _category 变体（前提是该分类表存在）。
     *
     * @return array [['name' => 'product', 'text' => '商品'], ['name' => 'product_category', ...], ...]
     */
    public function moduleList()
    {
        $columnModules = (array) Config::get('module.column_module');
        $installed = array_merge($columnModules, (array) Config::get('module.single_module'));

        $list = array();
        foreach ((array) Config::get('module.link_ai') as $module) {
            if (!in_array($module, $installed, true)) {
                continue;
            }
            $text = lang($module, $module);
            $list[] = array('name' => $module, 'text' => $text);
            if (in_array($module, $columnModules, true) && DB::rowExist($module . '_category')) {
                $list[] = array(
                    'name' => $module . '_category',
                    'text' => lang($module . '_category', $module . '_category'),
                );
            }
        }

        return $list;
    }

    /**
     * 取模块数据表的可生成字段集（排除系统列），带语言包标签。
     *
     * @param string $module 模块逻辑名（含 _category 变体）
     * @return array [['name' => ..., 'label' => ..., 'text' => ..., 'type' => 'string|integer|number'], ...]
     */
    public function fieldsFor($module)
    {
        $module = trim((string) $module);
        if ($module === '' || !DB::rowExist($module)) {
            return array();
        }

        $fields = array();
        $query = DB::query('SHOW COLUMNS FROM ' . DB::tableName($module));
        while ($column = DB::fetchArray($query)) {
            $fieldName = $column['Field'];
            if (in_array($fieldName, self::$systemColumns, true) || !$this->isAiGeneratable($fieldName)) {
                continue;
            }

            $langKey = $module . '_' . $fieldName;
            $fieldLabel = lang($langKey) !== '' ? lang($langKey) : lang($fieldName, $fieldName);

            $fields[] = array(
                'name' => $fieldName,
                'label' => $fieldLabel,
                'text' => $fieldName . ' ' . $fieldLabel,
                'type' => $this->jsonTypeFor($column['Type']),
                'max_length' => $this->maxLengthFor($column['Type']),
            );
        }

        return $fields;
    }

    /**
     * 字段是否可由文本模型生成（黑名单外）。
     *
     * @param string $fieldName
     * @return bool
     */
    private function isAiGeneratable($fieldName)
    {
        if (in_array($fieldName, self::$aiBlockedColumns, true)) {
            return false;
        }
        if (strpos($fieldName, 'is_') === 0) {
            return false;
        }
        foreach (self::$aiBlockedSuffixes as $suffix) {
            $len = strlen($suffix);
            if (strlen($fieldName) > $len && substr($fieldName, -$len) === $suffix) {
                return false;
            }
        }

        return true;
    }

    /**
     * 生成输出契约（JSON Schema），生成时按已选字段现算。
     *
     * batch 形态包一层 items 数组（批量条目）；fill / assist / translate 为单对象。
     *
     * @param string $module 模块逻辑名
     * @param array $selectedFields 已选字段名集合（空 = 全部可生成字段）
     * @param string $placement assist|fill|batch|translate
     * @return array JSON Schema（array 表达）
     */
    public function schemaFor($module, array $selectedFields, $placement)
    {
        $fields = $this->fieldsFor($module);
        if ($selectedFields) {
            $fields = array_values(array_filter($fields, function ($field) use ($selectedFields) {
                return in_array($field['name'], $selectedFields, true);
            }));
        }

        $properties = array();
        $required = array();
        foreach ($fields as $field) {
            $property = array(
                'type' => $field['type'],
                'description' => $this->descriptionFor($field),
            );
            if ($field['type'] === 'string' && $field['max_length'] > 0) {
                $property['maxLength'] = $field['max_length'];
            }
            $properties[$field['name']] = $property;
            $required[] = $field['name'];
        }

        $itemSchema = array(
            'type' => 'object',
            'properties' => $properties ? $properties : new \stdClass(),
            'required' => $required,
            'additionalProperties' => false,
        );

        if ($placement === 'batch') {
            return array(
                'type' => 'object',
                'properties' => array(
                    'items' => array(
                        'type' => 'array',
                        'items' => $itemSchema,
                    ),
                ),
                'required' => array('items'),
                'additionalProperties' => false,
            );
        }

        return $itemSchema;
    }

    /**
     * 生成 LLM 友好的字段 description：语言包标签 + 按字段名/类型的约束文案。
     *
     * @param array $field fieldsFor() 的单字段项
     * @return string
     */
    private function descriptionFor(array $field)
    {
        $name = $field['name'];
        $label = (string) $field['label'];

        $hint = '';
        if ($name === 'name' || $name === 'title' || substr($name, -6) === '_title') {
            $hint = lang('ai_schema_hint_title');
        } elseif (strpos($name, 'keywords') !== false) {
            $hint = lang('ai_schema_hint_keywords');
        } elseif ($name === 'description' || strpos($name, 'summary') !== false || strpos($name, 'brief') !== false) {
            $hint = lang('ai_schema_hint_summary');
        } elseif ($name === 'content' || substr($name, -8) === '_content' || strpos($name, 'detail') !== false) {
            $hint = lang('ai_schema_hint_content');
        } elseif ($field['type'] === 'integer') {
            $hint = lang('ai_schema_hint_integer');
        } elseif ($field['type'] === 'number') {
            $hint = lang('ai_schema_hint_number');
        }

        $parts = array($label);
        if ($hint !== '') {
            $parts[] = $hint;
        }
        if ($field['type'] === 'string' && $field['max_length'] > 0) {
            $parts[] = sprintf(lang('ai_schema_hint_maxlength'), $field['max_length']);
        }

        return implode('，', $parts);
    }

    /**
     * varchar/char 列的最大长度（其余类型返回 0）。
     *
     * @param string $columnType SHOW COLUMNS 的 Type 字段
     * @return int
     */
    private function maxLengthFor($columnType)
    {
        if (preg_match('/^(?:var)?char\((\d+)\)/i', (string) $columnType, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * MySQL 列类型映射为 JSON Schema 类型。
     *
     * @param string $columnType SHOW COLUMNS 的 Type 字段
     * @return string
     */
    private function jsonTypeFor($columnType)
    {
        $columnType = strtolower((string) $columnType);
        if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)/', $columnType)) {
            return 'integer';
        }
        if (preg_match('/^(decimal|float|double)/', $columnType)) {
            return 'number';
        }

        return 'string';
    }
}
