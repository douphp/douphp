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

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Support\Check;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 扩展字段定义规范化与值校验。
 *
 * 定义来自 dou_metadata；值来自内容表 metadata 列或 language_value 覆盖 JSON。
 * 所有方法保持纯计算，不读写数据库、不抛业务异常（调用方负责组装错误消息）。
 */
class MetadataSchema
{
    const SLUG_PATTERN = '/^[a-z][a-z0-9_]{0,29}$/';
    const MAX_FIELDS_PER_MODULE = 100;
    const MAX_MULTI_LIMIT = 20;
    const DEFAULT_IMAGE_SLOTS = 6;

    /** @var array */
    private static $types = array(
        'input',
        'textarea',
        'number',
        'date',
        'select',
        'radio',
        'checkbox',
        'switch',
        'image',
        'gallery',
        'file',
        'editor',
    );

    /**
     * @return array
     */
    public static function types()
    {
        return self::$types;
    }

    /**
     * @param string $type
     * @return bool
     */
    public static function isValidType($type)
    {
        return in_array((string) $type, self::$types, true);
    }

    /**
     * @param string $type
     * @return bool
     */
    public static function isMulti($type)
    {
        return in_array((string) $type, array('checkbox', 'gallery'), true);
    }

    /**
     * @param string $type
     * @return bool
     */
    public static function isAttachment($type)
    {
        return in_array((string) $type, array('image', 'gallery', 'file'), true);
    }

    /**
     * 选项文本按行拆分为去重数组。
     *
     * @param string $text
     * @return array
     */
    public static function parseOptions($text)
    {
        $items = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $row) {
            $row = trim($row);
            if ($row !== '' && !in_array($row, $items, true)) {
                $items[] = $row;
            }
        }

        return $items;
    }

    /**
     * 定义行规范化：过滤非法 slug/类型，拆分选项，收敛 limit，按 sort/id 排序。
     *
     * @param array $rows
     * @return array slug => definition
     */
    public static function normalizeDefinitions(array $rows)
    {
        $definitions = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $definition = self::normalizeDefinition($row);
            if ($definition === null) {
                continue;
            }
            $definitions[$definition['slug']] = $definition;
        }

        uasort($definitions, function ($a, $b) {
            if ((int) $a['sort'] === (int) $b['sort']) {
                return (int) $a['id'] - (int) $b['id'];
            }

            return (int) $a['sort'] - (int) $b['sort'];
        });

        return $definitions;
    }

    /**
     * @param array $row
     * @return array|null
     */
    public static function normalizeDefinition(array $row)
    {
        $slug = isset($row['slug']) ? trim((string) $row['slug']) : '';
        if (!preg_match(self::SLUG_PATTERN, $slug)) {
            return null;
        }

        $type = isset($row['type']) ? strtolower(trim((string) $row['type'])) : 'input';
        if (!self::isValidType($type)) {
            $type = 'input';
        }

        $limit = isset($row['limit']) ? (int) $row['limit'] : 0;
        if ($limit < 0) {
            $limit = 0;
        }
        if ($limit > self::MAX_MULTI_LIMIT) {
            $limit = self::MAX_MULTI_LIMIT;
        }

        return array(
            'id' => isset($row['id']) ? (int) $row['id'] : 0,
            'module' => isset($row['module']) ? (string) $row['module'] : '',
            'slug' => $slug,
            'label' => isset($row['label']) ? trim((string) $row['label']) : '',
            'type' => $type,
            'options' => self::parseOptions(isset($row['options']) ? $row['options'] : ''),
            'extensions' => isset($row['extensions']) ? strtolower(trim((string) $row['extensions'])) : '',
            'max_kb' => isset($row['max_kb']) ? (int) $row['max_kb'] : 0,
            'required' => !empty($row['required']) ? 1 : 0,
            'limit' => $limit,
            'tip' => isset($row['tip']) ? trim((string) $row['tip']) : '',
            'sort' => isset($row['sort']) ? (int) $row['sort'] : 50,
            'status' => isset($row['status']) ? (int) $row['status'] : 1,
        );
    }

    /**
     * 定义保存校验。
     *
     * @param array $data
     * @param array|null $existing 编辑时的原定义
     * @return array 错误消息列表
     */
    public static function validateDefinition(array $data, $existing = null)
    {
        $errors = array();
        $module = isset($data['module']) ? (string) $data['module'] : '';
        $slug = isset($data['slug']) ? trim((string) $data['slug']) : '';
        $label = isset($data['label']) ? trim((string) $data['label']) : '';
        $type = isset($data['type']) ? (string) $data['type'] : '';

        if (!MetadataModules::exists($module)) {
            $errors[] = lang('metadata_error_module', '所属模块不合法');
        }
        if (!preg_match(self::SLUG_PATTERN, $slug)) {
            $errors[] = lang('metadata_error_slug', '字段标识必须为小写字母开头，仅含小写字母、数字、下划线，最长 30 位');
        }
        if ($label === '' || self::length($label) > 150) {
            $errors[] = lang('metadata_error_label', '显示名不能为空且不能超过 150 个字符');
        }
        if (!self::isValidType($type)) {
            $errors[] = lang('metadata_error_type', '字段类型不合法');
        }
        if (is_array($existing)) {
            if ($slug !== '' && $slug !== $existing['slug']) {
                $errors[] = lang('metadata_error_slug_locked', '字段标识创建后不可修改');
            }
            if ($type !== '' && $type !== $existing['type']) {
                $errors[] = lang('metadata_error_type_locked', '字段类型创建后不可修改');
            }
        }

        if (in_array($type, array('select', 'radio', 'checkbox'), true)) {
            $options = self::parseOptions(isset($data['options']) ? $data['options'] : '');
            if (empty($options)) {
                $errors[] = lang('metadata_error_options', '选择类字段必须填写至少一个选项');
            }
        }

        if ($type === 'file') {
            $checked = self::validateFileExtensions(isset($data['extensions']) ? $data['extensions'] : '');
            if (!$checked['ok']) {
                $errors[] = $checked['error'];
            }
            $platformMax = self::platformUploadMaxKb();
            $maxKb = isset($data['max_kb']) ? (int) $data['max_kb'] : 0;
            if ($maxKb > $platformMax) {
                $errors[] = lang('metadata_error_max_kb', '文件大小上限不能超过平台默认值');
            }
        }

        $limit = isset($data['limit']) ? (int) $data['limit'] : 0;
        if ($limit < 0 || $limit > self::MAX_MULTI_LIMIT) {
            $errors[] = lang('metadata_error_limit', '数量上限必须在 0 到 20 之间');
        }

        return $errors;
    }

    /**
     * 校验一份值 map（默认语言或某个语言的显式覆盖）。
     *
     * @param array $schema
     * @param array $values
     * @param array $options 可选项：skip_required
     * @return array slug => 错误消息
     */
    public static function validateValues(array $schema, array $values, array $options = array())
    {
        $errors = array();
        $skipRequired = !empty($options['skip_required']);

        foreach ($schema as $slug => $field) {
            $exists = array_key_exists($slug, $values);
            $value = $exists ? $values[$slug] : null;
            $empty = self::isEmptyValue($field['type'], $value);

            if (!$skipRequired && !empty($field['required']) && $empty) {
                $errors[$slug] = self::message('validator_required', $field['label']);
                continue;
            }
            if (!$exists || $empty) {
                continue;
            }

            switch ($field['type']) {
                case 'number':
                    if (!is_scalar($value) || !is_numeric($value)) {
                        $errors[$slug] = self::message('validator_numeric', $field['label']);
                    }
                    break;

                case 'date':
                    if (!is_scalar($value) || !self::isValidDate($value)) {
                        $errors[$slug] = self::message('validator_date', $field['label']);
                    }
                    break;

                case 'select':
                case 'radio':
                    if (!is_scalar($value) || !in_array((string) $value, $field['options'], true)) {
                        $errors[$slug] = self::message('validator_in', $field['label'], array(':values' => implode('/', $field['options'])));
                    }
                    break;

                case 'switch':
                    if (!is_scalar($value) || !in_array((string) $value, array('0', '1'), true)) {
                        $errors[$slug] = self::message('validator_boolean', $field['label']);
                    }
                    break;

                case 'checkbox':
                    if (!is_array($value)) {
                        $errors[$slug] = self::message('validator_in', $field['label'], array(':values' => implode('/', $field['options'])));
                        break;
                    }
                    foreach ($value as $item) {
                        if (!in_array((string) $item, $field['options'], true)) {
                            $errors[$slug] = self::message('validator_in', $field['label'], array(':values' => implode('/', $field['options'])));
                            break;
                        }
                    }
                    if (!isset($errors[$slug]) && $field['limit'] > 0 && count($value) > $field['limit']) {
                        $errors[$slug] = self::message('validator_max', $field['label'], array(':max' => $field['limit']));
                    }
                    break;

                case 'image':
                case 'file':
                    if (!Check::fileNumber($value)) {
                        $errors[$slug] = lang('metadata_error_attachment', '附件编号不合法');
                    }
                    break;

                case 'gallery':
                    if (!is_array($value)) {
                        $errors[$slug] = lang('metadata_error_attachment', '附件编号不合法');
                        break;
                    }
                    foreach ($value as $item) {
                        if (!Check::fileNumber($item)) {
                            $errors[$slug] = lang('metadata_error_attachment', '附件编号不合法');
                            break;
                        }
                    }
                    if (!isset($errors[$slug]) && $field['limit'] > 0 && count($value) > $field['limit']) {
                        $errors[$slug] = self::message('validator_max', $field['label'], array(':max' => $field['limit']));
                    }
                    break;

                case 'input':
                case 'textarea':
                case 'editor':
                    if (!is_scalar($value) || self::length($value) > self::maxLength($field['type'])) {
                        $errors[$slug] = self::message('validator_max', $field['label'], array(':max' => self::maxLength($field['type'])));
                    }
                    break;
            }
        }

        return $errors;
    }

    /**
     * 空值判定：标量空串/空数组为空；'0' 与 0 不是空。
     *
     * @param string $type
     * @param mixed $value
     * @return bool
     */
    public static function isEmptyValue($type, $value)
    {
        if (self::isMulti($type)) {
            return !is_array($value) || count($value) === 0;
        }

        return $value === null || $value === '';
    }

    /**
     * 单值归一化（类型转换 + 去重），空值返回 null。
     *
     * @param array $field
     * @param mixed $value
     * @return mixed
     */
    public static function normalizeValue(array $field, $value)
    {
        $type = $field['type'];

        if (self::isEmptyValue($type, $value)) {
            return null;
        }

        switch ($type) {
            case 'number':
                if (!is_numeric($value)) {
                    return null;
                }
                return (strpos((string) $value, '.') !== false) ? (float) $value : (int) $value;

            case 'switch':
                return (is_scalar($value) && (string) $value === '1') ? 1 : 0;

            case 'checkbox':
                $items = array();
                foreach ((array) $value as $item) {
                    $item = (string) $item;
                    if ($item !== '' && !in_array($item, $items, true)) {
                        $items[] = $item;
                    }
                }
                return empty($items) ? null : $items;

            case 'gallery':
                $items = array();
                foreach ((array) $value as $item) {
                    $item = (string) $item;
                    if ($item !== '' && !in_array($item, $items, true)) {
                        $items[] = $item;
                    }
                }
                return empty($items) ? null : $items;

            case 'image':
            case 'file':
                return is_scalar($value) ? (string) $value : null;

            case 'date':
            case 'select':
            case 'radio':
            case 'input':
            case 'textarea':
            case 'editor':
                return is_scalar($value) ? (string) $value : null;
        }

        return null;
    }

    /**
     * gallery 实际槽位数（limit=0 用默认 6）。
     *
     * @param array $field
     * @return int
     */
    public static function limitFor(array $field)
    {
        $limit = isset($field['limit']) ? (int) $field['limit'] : 0;
        if ($limit > 0) {
            return min($limit, self::MAX_MULTI_LIMIT);
        }

        return $field['type'] === 'gallery' ? self::DEFAULT_IMAGE_SLOTS : 0;
    }

    /**
     * file 类型平台扩展名基线。
     *
     * @return array
     */
    public static function platformFileExtensions()
    {
        $default = 'jpg,jpeg,gif,png,webp,ico,zip,rar,7z,pdf,xls,xlsx,doc,docx,ppt,pptx,txt,csv,wmv,avi,mp4,flv,mp3';
        $value = (string) Config::get('filesystems.upload_defaults.metadata_allow_extensions', $default);
        $items = array();
        foreach (explode(',', strtolower($value)) as $item) {
            $item = trim($item);
            if ($item !== '' && !in_array($item, $items, true)) {
                $items[] = $item;
            }
        }

        return empty($items) ? explode(',', $default) : $items;
    }

    /**
     * 永久拒绝的危险扩展名。
     *
     * @return array
     */
    public static function dangerousExtensions()
    {
        return array(
            'php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'html', 'htm', 'js', 'svg', 'xml',
            'exe', 'sh', 'bat', 'cmd', 'msi', 'jar', 'com', 'scr', 'vbs', 'ps1', 'cgi', 'pl',
            'py', 'rb', 'asp', 'aspx', 'jsp', 'jspx',
        );
    }

    /**
     * 校验字段扩展名：必须为平台基线子集且不含危险扩展名。
     *
     * @param string $extensions
     * @return array ok/extensions/error
     */
    public static function validateFileExtensions($extensions)
    {
        $extensions = strtolower(trim((string) $extensions));
        if ($extensions === '') {
            $extensions = implode(',', self::platformFileExtensions());
        }

        $platform = self::platformFileExtensions();
        $dangerous = self::dangerousExtensions();
        $items = array();
        foreach (array_map('trim', explode(',', $extensions)) as $item) {
            if ($item === '') {
                continue;
            }
            if (!preg_match('/^[a-z0-9]{1,10}$/', $item)) {
                return array('ok' => false, 'extensions' => '', 'error' => lang('metadata_error_extension_format', '扩展名格式不合法'));
            }
            if (in_array($item, $dangerous, true)) {
                return array('ok' => false, 'extensions' => '', 'error' => lang('metadata_error_extension_dangerous', '包含被禁止的危险扩展名'));
            }
            if (!in_array($item, $platform, true)) {
                return array('ok' => false, 'extensions' => '', 'error' => lang('metadata_error_extension_outside', '扩展名超出平台允许范围'));
            }
            if (!in_array($item, $items, true)) {
                $items[] = $item;
            }
        }

        if (empty($items)) {
            return array('ok' => false, 'extensions' => '', 'error' => lang('metadata_error_extension_empty', '至少填写一个允许的扩展名'));
        }

        return array('ok' => true, 'extensions' => implode(',', $items), 'error' => '');
    }

    /**
     * file 字段实际生效的上传选项。
     *
     * @param array $field
     * @return array extensions/max_kb
     */
    public static function effectiveFileOptions(array $field)
    {
        $checked = self::validateFileExtensions(isset($field['extensions']) ? $field['extensions'] : '');
        $extensions = $checked['ok'] ? $checked['extensions'] : implode(',', self::platformFileExtensions());
        $platformMax = self::platformUploadMaxKb();
        $maxKb = isset($field['max_kb']) ? (int) $field['max_kb'] : 0;
        if ($maxKb <= 0 || $maxKb > $platformMax) {
            $maxKb = $platformMax;
        }

        return array('extensions' => $extensions, 'max_kb' => $maxKb);
    }

    /**
     * @return int
     */
    public static function platformUploadMaxKb()
    {
        $max = (int) Config::get('filesystems.upload_defaults.upload_max_kb', 2048);

        return $max > 0 ? $max : 2048;
    }

    /**
     * @param string $type
     * @return int
     */
    public static function maxLength($type)
    {
        if ($type === 'editor') {
            return 200000;
        }
        if ($type === 'textarea') {
            return 20000;
        }

        return 5000;
    }

    /**
     * @param string $value
     * @return int
     */
    public static function length($value)
    {
        $value = (string) $value;

        return function_exists('mb_strlen') ? mb_strlen($value, 'utf-8') : strlen($value);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private static function isValidDate($value)
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $value, $matches)) {
            return false;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }

    /**
     * 复用验证器语言键，替换 :field 等占位符。
     *
     * @param string $key
     * @param string $field
     * @param array $replace
     * @return string
     */
    private static function message($key, $field, array $replace = array())
    {
        $message = lang($key, $key);
        $replace[':field'] = $field;

        return strtr($message, $replace);
    }
}
