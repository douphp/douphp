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
 * 数值辅助工具类
 *
 * 提供常见数值归一化能力，无状态纯静态调用。
 * 兼容 PHP 5.6+，无外部依赖。
 */
class Num
{
    /**
     * 将输入转换为整数；空值/非法值返回 0
     *
     * @param mixed $value
     * @return int
     */
    public static function toIntOrZero($value)
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return 0;
        }

        return (int) $value;
    }

    /**
     * 将输入转换为浮点数；空值/非法值返回 0.0
     *
     * @param mixed $value
     * @return float
     */
    public static function toFloatOrZero($value)
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return 0.0;
        }

        return (float) $value;
    }

    /**
     * 判断输入是否为数值（含数字字符串）
     *
     * @param mixed $value
     * @return bool
     */
    public static function isNumeric($value)
    {
        return is_numeric($value);
    }

    /**
     * 限制数值在给定区间内
     *
     * @param mixed $value
     * @param mixed $min
     * @param mixed $max
     * @return float
     */
    public static function clamp($value, $min, $max)
    {
        $value = static::toFloatOrZero($value);
        $min = static::toFloatOrZero($min);
        $max = static::toFloatOrZero($max);

        if ($min > $max) {
            $tmp = $min;
            $min = $max;
            $max = $tmp;
        }

        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }

    /**
     * 比较两个数值是否近似相等（浮点容差）
     *
     * @param mixed $a
     * @param mixed $b
     * @param float $epsilon
     * @return bool
     */
    public static function equals($a, $b, $epsilon = 0.000001)
    {
        $a = static::toFloatOrZero($a);
        $b = static::toFloatOrZero($b);
        $epsilon = abs((float) $epsilon);
        if ($epsilon <= 0) {
            $epsilon = 0.000001;
        }

        return abs($a - $b) <= $epsilon;
    }

    /**
     * 数值是否大于 0
     *
     * @param mixed $value
     * @return bool
     */
    public static function isPositive($value)
    {
        return static::toFloatOrZero($value) > 0;
    }

    /**
     * 数值是否小于 0
     *
     * @param mixed $value
     * @return bool
     */
    public static function isNegative($value)
    {
        return static::toFloatOrZero($value) < 0;
    }

    /**
     * 获取绝对值
     *
     * @param mixed $value
     * @return float
     */
    public static function abs($value)
    {
        return abs(static::toFloatOrZero($value));
    }

    /**
     * 安全除法（除数无效时返回默认值）
     *
     * @param mixed $dividend
     * @param mixed $divisor
     * @param float $default
     * @return float
     */
    public static function safeDivide($dividend, $divisor, $default = 0.0)
    {
        $dividend = static::toFloatOrZero($dividend);
        $divisor = static::toFloatOrZero($divisor);
        if (static::equals($divisor, 0)) {
            return (float) $default;
        }

        return $dividend / $divisor;
    }

    /**
     * 按精度四舍五入
     *
     * @param mixed $value
     * @param int $precision
     * @return float
     */
    public static function round($value, $precision = 2)
    {
        return round(static::toFloatOrZero($value), (int) $precision);
    }

    /**
     * 格式化数值字符串
     *
     * @param mixed $value
     * @param int $decimals
     * @param string $decimalPoint
     * @param string $thousandsSep
     * @return string
     */
    public static function format($value, $decimals = 2, $decimalPoint = '.', $thousandsSep = ',')
    {
        return number_format(static::toFloatOrZero($value), (int) $decimals, $decimalPoint, $thousandsSep);
    }
}
