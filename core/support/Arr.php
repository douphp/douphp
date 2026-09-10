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

namespace Dou\Core\Support;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 数组辅助工具类
 *
 * 提供安全、便捷的数组操作方法，无状态纯静态调用。
 * 兼容 PHP 5.6+，无外部依赖。
 */
class Arr
{
    /**
     * 判断给定值是否可被当作数组访问（数组或 ArrayAccess 对象）
     *
     * @param mixed $value
     * @return bool
     */
    public static function accessible($value)
    {
        return is_array($value) || $value instanceof \ArrayAccess;
    }

    /**
     * 判断键是否存在于数组中（即使值为 null）
     *
     * @param array|\ArrayAccess $array
     * @param string|int $key
     * @return bool
     */
    public static function exists($array, $key)
    {
        if ($array instanceof \ArrayAccess) {
            return $array->offsetExists($key);
        }

        return array_key_exists($key, $array);
    }

    /**
     * 使用“点”语法从数组中获取值
     *
     * @param array|\ArrayAccess $array
     * @param string|int|null $key
     * @param mixed $default
     * @return mixed
     */
    public static function get($array, $key, $default = null)
    {
        if (!static::accessible($array)) {
            return $default;
        }

        if (is_null($key)) {
            return $array;
        }

        if (static::exists($array, $key)) {
            return $array[$key];
        }

        if (strpos($key, '.') === false) {
            return isset($array[$key]) ? $array[$key] : $default;
        }

        foreach (explode('.', $key) as $segment) {
            if (static::accessible($array) && static::exists($array, $segment)) {
                $array = $array[$segment];
            } else {
                return $default;
            }
        }

        return $array;
    }

    /**
     * 使用“点”语法向数组中设置值（自动创建中间数组）
     *
     * @param array $array
     * @param string $key
     * @param mixed $value
     * @return array
     */
    public static function set(&$array, $key, $value)
    {
        if (is_null($key)) {
            return $array = $value;
        }

        $keys = explode('.', $key);

        while (count($keys) > 1) {
            $key = array_shift($keys);

            if (!isset($array[$key]) || !is_array($array[$key])) {
                $array[$key] = array();
            }

            $array = &$array[$key];
        }

        $array[array_shift($keys)] = $value;

        return $array;
    }

    /**
     * 检查数组中是否存在指定键（支持“点”语法）
     *
     * @param array|\ArrayAccess $array
     * @param string|array $keys
     * @return bool
     */
    public static function has($array, $keys)
    {
        if (is_null($keys)) {
            return false;
        }

        $keys = (array) $keys;

        if (!static::accessible($array)) {
            return false;
        }

        foreach ($keys as $key) {
            $subKeyArray = $array;

            if (static::exists($array, $key)) {
                continue;
            }

            foreach (explode('.', $key) as $segment) {
                if (static::accessible($subKeyArray) && static::exists($subKeyArray, $segment)) {
                    $subKeyArray = $subKeyArray[$segment];
                } else {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * 只返回数组中指定的键值对
     *
     * @param array $array
     * @param array $keys
     * @return array
     */
    public static function only($array, $keys)
    {
        return array_intersect_key($array, array_flip((array) $keys));
    }

    /**
     * 排除数组中指定的键，返回剩余部分
     *
     * @param array $array
     * @param array $keys
     * @return array
     */
    public static function except($array, $keys)
    {
        static::forget($array, (array) $keys);

        return $array;
    }

    /**
     * 从数组中删除指定键（支持“点”语法）
     *
     * @param array $array
     * @param string|array $keys
     * @return void
     */
    public static function forget(&$array, $keys)
    {
        $original = &$array;

        $keys = (array) $keys;

        if (count($keys) === 0) {
            return;
        }

        foreach ($keys as $key) {
            if (static::exists($array, $key)) {
                unset($array[$key]);
                continue;
            }

            $parts = explode('.', $key);
            $array = &$original;

            while (count($parts) > 1) {
                $part = array_shift($parts);

                if (isset($array[$part]) && is_array($array[$part])) {
                    $array = &$array[$part];
                } else {
                    continue 2;
                }
            }

            unset($array[array_shift($parts)]);
        }
    }

    /**
     * 从多维数组中提取一列值
     *
     * @param array $array
     * @param string $value
     * @param string|null $key
     * @return array
     */
    public static function pluck($array, $value, $key = null)
    {
        $results = array();

        foreach ($array as $item) {
            $itemValue = static::get($item, $value);

            if (is_null($key)) {
                $results[] = $itemValue;
            } else {
                $itemKey = static::get($item, $key);
                $results[$itemKey] = $itemValue;
            }
        }

        return $results;
    }

    /**
     * 将多个数组合并为一个（类似 array_merge，但能处理嵌套数组）
     *
     * @param array $array
     * @return array
     */
    public static function collapse($array)
    {
        $results = array();

        foreach ($array as $values) {
            if (!is_array($values)) {
                continue;
            }

            $results = array_merge($results, $values);
        }

        return $results;
    }

    /**
     * 如果值不是数组，则将其包装成数组
     *
     * @param mixed $value
     * @return array
     */
    public static function wrap($value)
    {
        if (is_null($value)) {
            return array();
        }

        return is_array($value) ? $value : array($value);
    }

    /**
     * 返回数组的第一个元素
     *
     * @param array $array
     * @param mixed $default
     * @return mixed
     */
    public static function first($array, $default = null)
    {
        if (empty($array)) {
            return $default;
        }

        foreach ($array as $item) {
            return $item;
        }
    }

    /**
     * 返回数组的最后一个元素
     *
     * @param array $array
     * @param mixed $default
     * @return mixed
     */
    public static function last($array, $default = null)
    {
        if (empty($array)) {
            return $default;
        }

        return end($array);
    }

    /**
     * 将数组导出为可被 PHP include 回去的代码字符串
     *
     * 与 var_export($value, true) include 等价，但对顺序数组（连续数字键 0..n-1）省略
     * 数字下标。任何合法输出都能被 PHP 解析回完全相同的数组；标量分支统一委托
     * var_export，避免重新发明字符串转义、浮点格式、null/bool 等细节。
     *
     * @param array $value
     * @param int $indent 当前缩进层级（递归用，外部调用保持默认 0）
     * @param int $indentStep 每层缩进空格数（与仓库 4 空格规范一致，默认 4）
     * @return string
     */
    public static function export(array $value, $indent = 0, $indentStep = 4)
    {
        $indentStep = (int) $indentStep;
        if ($indentStep < 1) {
            $indentStep = 4;
        }

        if (count($value) === 0) {
            return "array (\n" . str_repeat(' ', $indentStep * $indent) . ')';
        }

        $pad = str_repeat(' ', $indentStep * ($indent + 1));
        $padClose = str_repeat(' ', $indentStep * $indent);
        $isList = array_keys($value) === range(0, count($value) - 1);

        $lines = array();
        foreach ($value as $k => $v) {
            $line = $pad;
            if (!$isList) {
                $line .= var_export($k, true) . ' => ';
            }
            if (is_array($v)) {
                $line .= static::export($v, $indent + 1, $indentStep);
            } else {
                $line .= var_export($v, true);
            }
            $line .= ',';
            $lines[] = $line;
        }

        return "array (\n" . implode("\n", $lines) . "\n" . $padClose . ')';
    }
}
