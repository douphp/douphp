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
 * Release Date: 2026-11-11
 */

namespace Dou\Core\Service\Aigc;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * aigc 表单字段定义工具：类型白名单、选项解析、默认字段模板、值归一。
 */
class FieldDefinition
{
    /**
     * 支持的表单类型（刻意比 form 模块精简）。
     *
     * @return array
     */
    public static function types()
    {
        return array('input', 'textarea', 'number', 'select', 'radio', 'checkbox');
    }

    /**
     * 是否为多选类型。
     *
     * @param string $type
     * @return bool
     */
    public static function isMultiple($type)
    {
        return $type === 'checkbox';
    }

    /**
     * 是否使用 option 列表。
     *
     * @param string $type
     * @return bool
     */
    public static function hasOptions($type)
    {
        return in_array($type, array('select', 'radio', 'checkbox'), true);
    }

    /**
     * 解析选项文本：每行一个，`值|显示名` 或仅显示名（值同显示名）。
     *
     * @param string $text
     * @return array [{value,label}]
     */
    public static function parseOptions($text)
    {
        $options = array();
        $lines = preg_split('/\r\n|\r|\n/', (string) $text);
        foreach ((array) $lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (strpos($line, '|') !== false) {
                $parts = explode('|', $line, 2);
                $value = trim($parts[0]);
                $label = trim($parts[1]);
            } else {
                $value = $line;
                $label = $line;
            }
            if ($value === '' && $label === '') {
                continue;
            }
            $options[] = array('value' => $value, 'label' => $label);
        }

        return $options;
    }

    /**
     * 选项数组 → 文本。
     *
     * @param array $options
     * @return string
     */
    public static function joinOptions(array $options)
    {
        $lines = array();
        foreach ($options as $option) {
            if (!is_array($option) || !isset($option['value'])) {
                continue;
            }
            $value = (string) $option['value'];
            $label = isset($option['label']) ? (string) $option['label'] : $value;
            $lines[] = $value === $label ? $label : $value . '|' . $label;
        }

        return implode("\n", $lines);
    }

    /**
     * 归一单个字段值（按类型 + 选项白名单）。
     *
     * @param array $field
     * @param mixed $raw
     * @return array {ok, value, error}
     */
    public static function normalizeValue(array $field, $raw)
    {
        $type = isset($field['type']) ? (string) $field['type'] : 'input';
        $mustFill = !empty($field['must_fill']);

        if (self::isMultiple($type)) {
            $values = is_array($raw) ? $raw : ($raw === '' || $raw === null ? array() : array($raw));
            $clean = array();
            $allowed = self::optionValues($field);
            foreach ($values as $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }
                if ($allowed && !in_array($value, $allowed, true)) {
                    return array('ok' => false, 'value' => '', 'error' => 'invalid option');
                }
                $clean[] = $value;
            }
            if ($mustFill && !$clean) {
                return array('ok' => false, 'value' => '', 'error' => 'required');
            }

            return array('ok' => true, 'value' => implode(',', $clean), 'error' => '');
        }

        $value = is_array($raw) ? '' : trim((string) $raw);
        if ($mustFill && $value === '') {
            return array('ok' => false, 'value' => '', 'error' => 'required');
        }
        if ($type === 'number' && $value !== '' && !is_numeric($value)) {
            return array('ok' => false, 'value' => '', 'error' => 'numeric');
        }
        if (in_array($type, array('select', 'radio'), true) && $value !== '') {
            $allowed = self::optionValues($field);
            if ($allowed && !in_array($value, $allowed, true)) {
                return array('ok' => false, 'value' => '', 'error' => 'invalid option');
            }
        }

        return array('ok' => true, 'value' => $value, 'error' => '');
    }

    /**
     * 字段可选值集合。
     *
     * @param array $field
     * @return array
     */
    public static function optionValues(array $field)
    {
        $values = array();
        foreach (self::parseOptions(isset($field['option']) ? $field['option'] : '') as $option) {
            $values[] = $option['value'];
        }

        return $values;
    }

    /**
     * 按应用类型生成默认字段模板。
     *
     * @param string $type chat|image|video|audio|ppt|excel
     * @return array
     */
    public static function defaultsForAppType($type)
    {
        $prompt = array(
            'name' => '提示词',
            'slug' => 'prompt',
            'type' => 'textarea',
            'option' => '',
            'placeholder' => '描述你想生成的内容',
            'tip' => '',
            'default_value' => '',
            'must_fill' => 1,
            'width' => 12,
            'sort' => 10,
            'status' => 1,
        );

        switch ($type) {
            case 'image':
                return array(
                    $prompt,
                    array('name' => '尺寸', 'slug' => 'size', 'type' => 'select', 'option' => "1024x1024|1024×1024\n768x1024|768×1024\n1024x768|1024×768", 'placeholder' => '', 'tip' => '', 'default_value' => '1024x1024', 'must_fill' => 0, 'width' => 6, 'sort' => 20, 'status' => 1),
                    array('name' => '数量', 'slug' => 'n', 'type' => 'number', 'option' => '', 'placeholder' => '1-4', 'tip' => '', 'default_value' => '1', 'must_fill' => 0, 'width' => 6, 'sort' => 30, 'status' => 1),
                    array('name' => '风格', 'slug' => 'style', 'type' => 'input', 'option' => '', 'placeholder' => '如：写实、插画、赛博朋克', 'tip' => '', 'default_value' => '', 'must_fill' => 0, 'width' => 12, 'sort' => 40, 'status' => 1),
                );
            case 'video':
                return array(
                    $prompt,
                    array('name' => '时长（秒）', 'slug' => 'duration', 'type' => 'select', 'option' => "5\n10", 'placeholder' => '', 'tip' => '', 'default_value' => '5', 'must_fill' => 0, 'width' => 6, 'sort' => 20, 'status' => 1),
                    array('name' => '分辨率', 'slug' => 'resolution', 'type' => 'select', 'option' => "720P\n1080P", 'placeholder' => '', 'tip' => '', 'default_value' => '720P', 'must_fill' => 0, 'width' => 6, 'sort' => 30, 'status' => 1),
                );
            case 'audio':
                return array(
                    array('name' => '合成文本', 'slug' => 'text', 'type' => 'textarea', 'option' => '', 'placeholder' => '输入要转换为语音的文字', 'tip' => '', 'default_value' => '', 'must_fill' => 1, 'width' => 12, 'sort' => 10, 'status' => 1),
                    array('name' => '音色', 'slug' => 'voice', 'type' => 'input', 'option' => '', 'placeholder' => '如：Cherry', 'tip' => '', 'default_value' => 'Cherry', 'must_fill' => 0, 'width' => 6, 'sort' => 20, 'status' => 1),
                    array('name' => '语速', 'slug' => 'speed', 'type' => 'number', 'option' => '', 'placeholder' => '1.0', 'tip' => '', 'default_value' => '1.0', 'must_fill' => 0, 'width' => 6, 'sort' => 30, 'status' => 1),
                );
            case 'ppt':
                return array(
                    $prompt,
                    array('name' => '页数', 'slug' => 'slides', 'type' => 'number', 'option' => '', 'placeholder' => '3-30', 'tip' => '', 'default_value' => '12', 'must_fill' => 0, 'width' => 6, 'sort' => 20, 'status' => 1),
                    array('name' => '风格', 'slug' => 'style', 'type' => 'input', 'option' => '', 'placeholder' => '如：商务简约', 'tip' => '', 'default_value' => '', 'must_fill' => 0, 'width' => 6, 'sort' => 30, 'status' => 1),
                );
            case 'excel':
                return array(
                    $prompt,
                    array('name' => '工作表数', 'slug' => 'sheets', 'type' => 'number', 'option' => '', 'placeholder' => '1-5', 'tip' => '', 'default_value' => '1', 'must_fill' => 0, 'width' => 6, 'sort' => 20, 'status' => 1),
                );
            default:
                return array();
        }
    }
}
