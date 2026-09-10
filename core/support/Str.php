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
 * 字符串辅助工具类
 *
 * 提供安全、便捷的字符串操作方法，无状态纯静态调用。
 * 兼容 PHP 5.6+，无外部依赖。
 */
class Str
{
    /**
     * 生成 URL 友好的 slug（别名）
     *
     * @param string $title 原始字符串
     * @param string $separator 分隔符，默认 '-'
     * @return string
     */
    public static function slug($title, $separator = '-')
    {
        $title = strtolower(trim($title));
        // 移除所有非字母数字和空格的字符
        $title = preg_replace('/[^\p{L}\p{N}\s]/u', '', $title);
        // 将连续空格替换为分隔符
        $title = preg_replace('/[\s]+/', $separator, $title);
        // 移除首尾分隔符
        return trim($title, $separator);
    }

    /**
     * 生成指定长度的随机字符串
     *
     * @param int $length 长度
     * @return string
     */
    public static function random($length = 16)
    {
        $string = '';
        while (($len = strlen($string)) < $length) {
            $size = $length - $len;
            if (function_exists('random_bytes')) {
                $bytes = random_bytes($size);
            } elseif (function_exists('openssl_random_pseudo_bytes')) {
                $bytes = openssl_random_pseudo_bytes($size);
            } else {
                // 降级方案
                $bytes = '';
                for ($i = 0; $i < $size; $i++) {
                    $bytes .= chr(mt_rand(0, 255));
                }
            }
            // 使用 base64 编码并移除特殊字符
            $string .= substr(str_replace(['/', '+', '='], '', base64_encode($bytes)), 0, $size);
        }
        return $string;
    }

    /**
     * 生成 URL 安全的十六进制随机 token
     *
     * 默认 32 字节熵 → 64 字符十六进制，适用于会话/记住我 cookie token。
     *
     * @param int $bytes 原始随机字节数，默认 32
     * @return string 长度为 $bytes * 2 的小写十六进制字符串
     */
    public static function randomHex($bytes = 32)
    {
        $bytes = (int) $bytes;
        if ($bytes <= 0) {
            $bytes = 32;
        }

        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($bytes));
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            $raw = openssl_random_pseudo_bytes($bytes, $strong);
            if ($strong || $raw !== false) {
                return bin2hex($raw);
            }
        }

        // 降级：弱随机 + sha256 摘要
        $weak = '';
        for ($i = 0; $i < $bytes; $i++) {
            $weak .= chr(mt_rand(0, 255));
        }
        $salt = md5(uniqid('', true) . microtime(true) . mt_rand());
        return substr(hash('sha256', $weak . $salt), 0, $bytes * 2);
    }

    /**
     * 将仅含空白或空字符串的值规范为 null。
     *
     * 适用于 UNIQUE 可空字段（如 user.telphone / user.email）：
     * 空串会触发 #1062 重复键冲突，需统一转 SQL NULL；非空字符串去除首尾空白。
     *
     * @param mixed $value
     * @return string|null 非空时返回 trim 后的字符串，否则返回 null
     */
    public static function nullIfEmpty($value)
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            $value = (string) $value;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * 执行randomByType操作。
     *
     * @param string $type 参数type。
     * @param int $length 参数length。
     * @param string $prefix 参数prefix。
     * @param string $customChars 参数customChars。
     * @return mixed 返回结果。
     */
    public static function randomByType($type = 'number', $length = 6, $prefix = '', $customChars = '')
    {
        $chars = '';
        // 设置随机字符范围，去掉了容易混淆的字符oOLl和数字01
        if (strpos($type, 'number') !== false) {
            $chars = '0123456789';
        }
        if (strpos($type, 'LETTER') !== false) {
            $chars .= 'ABCDEFGHIJKMNPQRSTUVWXYZ';
        }
        if (strpos($type, 'letter') !== false) {
            $chars .= 'abcdefghijklmnopqrstuvwxyz';
        }

        // 如果有自定义的字符则包含进去
        $chars = $chars . $customChars;
        if ($chars === '') {
            $chars = '0123456789';
        }

        $string = '';
        for ($i = 0; $i < $length; $i++) {
            $string .= $chars[mt_rand(0, strlen($chars) - 1)];
        }

        return $prefix . $string;
    }

    /**
     * 截取字符串到指定长度
     *
     * @param mixed $str
     * @param mixed $length
     * @param bool $clearSpace
     * @param string $charset
     * @return mixed
     */
    public static function excerpt($str, $length, $clearSpace = true, $charset = 'utf-8')
    {
        if ($str === null || !is_string($str)) {
            return false;
        }

        $str = trim($str);
        $str = strip_tags($str, '');
        $str = preg_replace("/\r\n/", '', $str);
        $str = preg_replace("/\r/", '', $str);
        $str = preg_replace("/\n/", '', $str);

        if ($clearSpace) {
            $str = preg_replace("/\t/", '', $str);
            $str = preg_replace("/ /", '', $str);
            $str = preg_replace("/&nbsp;/", '', $str);
        }
        $str = trim($str);

        if (function_exists('mb_substr')) {
            return mb_substr($str, 0, $length, $charset);
        }

        $patterns = array(
            'utf-8' => "/[\x01-\x7f]|[\xc2-\xdf][\x80-\xbf]|[\xe0-\xef][\x80-\xbf]{2}|[\xf0-\xff][\x80-\xbf]{3}/",
            'gbk' => "/[\x01-\x7f]|[\x81-\xfe][\x40-\xfe]/"
        );
        if (!isset($patterns[$charset])) {
            $charset = 'utf-8';
        }

        preg_match_all($patterns[$charset], $str, $match);
        return join('', array_slice($match[0], 0, $length));
    }

    /**
     * 截取字符串到指定长度（支持中文）
     *
     * @param string $value 原始字符串
     * @param int $limit 限制长度
     * @param string $end 结尾追加字符串
     * @return string
     */
    public static function limit($value, $limit = 100, $end = '...')
    {
        if (mb_strwidth($value, 'UTF-8') <= $limit) {
            return $value;
        }
        return rtrim(mb_strimwidth($value, 0, $limit, '', 'UTF-8')) . $end;
    }

    /**
     * 判断字符串是否包含指定子串
     *
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    public static function contains($haystack, $needle)
    {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }

    /**
     * 判断字符串是否以指定子串开头
     *
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    public static function startsWith($haystack, $needle)
    {
        return $needle !== '' && mb_strpos($haystack, $needle) === 0;
    }

    /**
     * 判断字符串是否以指定子串结尾
     *
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    public static function endsWith($haystack, $needle)
    {
        return $needle !== '' && mb_substr($haystack, -mb_strlen($needle)) === $needle;
    }

    /**
     * 转换为驼峰命名（camelCase）
     *
     * @param string $value
     * @return string
     */
    public static function camel($value)
    {
        return lcfirst(static::studly($value));
    }

    /**
     * 转换为帕斯卡命名（StudlyCase）
     *
     * @param string $value
     * @return string
     */
    public static function studly($value)
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));
        return str_replace(' ', '', $value);
    }

    /**
     * 转换为蛇形命名（snake_case）
     *
     * @param string $value
     * @param string $delimiter 分隔符，默认 '_'
     * @return string
     */
    public static function snake($value, $delimiter = '_')
    {
        $value = preg_replace('/\s+/u', '', $value);
        return mb_strtolower(preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value));
    }

    /**
     * 转换为短横线命名（kebab-case）
     *
     * @param string $value
     * @return string
     */
    public static function kebab($value)
    {
        return static::snake($value, '-');
    }

    /**
     * 替换字符串中首次出现的指定内容
     *
     * @param string $search
     * @param string $replace
     * @param string $subject
     * @return string
     */
    public static function replaceFirst($search, $replace, $subject)
    {
        if ($search == '') {
            return $subject;
        }
        $position = strpos($subject, $search);
        if ($position !== false) {
            return substr_replace($subject, $replace, $position, strlen($search));
        }
        return $subject;
    }

    /**
     * 替换字符串中最后出现的指定内容
     *
     * @param string $search
     * @param string $replace
     * @param string $subject
     * @return string
     */
    public static function replaceLast($search, $replace, $subject)
    {
        $position = strrpos($subject, $search);
        if ($position !== false) {
            return substr_replace($subject, $replace, $position, strlen($search));
        }
        return $subject;
    }

    /**
     * 移除字符串左侧的指定子串
     *
     * @param string $subject
     * @param string $prefix
     * @return string
     */
    public static function removeLeft($subject, $prefix)
    {
        if (static::startsWith($subject, $prefix)) {
            return substr($subject, strlen($prefix));
        }
        return $subject;
    }

    /**
     * 移除字符串右侧的指定子串
     *
     * @param string $subject
     * @param string $suffix
     * @return string
     */
    public static function removeRight($subject, $suffix)
    {
        if (static::endsWith($subject, $suffix)) {
            return substr($subject, 0, -strlen($suffix));
        }
        return $subject;
    }

    /**
     * 给字符串添加前缀
     *
     * @param string $value
     * @param string $prefix
     * @return string
     */
    public static function prefix($value, $prefix)
    {
        return $prefix . $value;
    }

    /**
     * 给字符串添加后缀
     *
     * @param string $value
     * @param string $suffix
     * @return string
     */
    public static function suffix($value, $suffix)
    {
        return $value . $suffix;
    }

    /**
     * 脱敏处理（如手机号、邮箱）
     *
     * @param string $value 原始字符串
     * @param int $start 开始位置
     * @param int $length 替换长度
     * @param string $mask 替换字符，默认 '*'
     * @return string
     */
    public static function mask($value, $start, $length, $mask = '*')
    {
        if (empty($value)) {
            return $value;
        }
        $len = mb_strlen($value, 'UTF-8');
        if ($start < 0) {
            $start = $len + $start;
        }
        if ($length <= 0) {
            return $value;
        }
        $masked = '';
        for ($i = 0; $i < $length; $i++) {
            $masked .= $mask;
        }
        return mb_substr($value, 0, $start, 'UTF-8')
            . $masked
            . mb_substr($value, $start + $length, null, 'UTF-8');
    }

    /**
     * 将字符串转换为标题形式（每个单词首字母大写）
     *
     * @param string $value
     * @return string
     */
    public static function title($value)
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * 获取字符串长度（支持中文）
     *
     * @param string $value
     * @return int
     */
    public static function length($value)
    {
        return mb_strlen($value, 'UTF-8');
    }

    /**
     * 反转字符串（支持中文）
     *
     * @param string $value
     * @return string
     */
    public static function reverse($value)
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        return implode('', array_reverse($chars));
    }

    /**
     * 判断字符串是否为空（包括全空格）
     *
     * @param string $value
     * @return bool
     */
    public static function isBlank($value)
    {
        return trim($value) === '';
    }

    /**
     * 判断字符串是否非空
     *
     * @param string $value
     * @return bool
     */
    public static function isNotBlank($value)
    {
        return !static::isBlank($value);
    }

    /**
     * 生成 UUID v4
     *
     * @return string
     */
    public static function uuid()
    {
        if (function_exists('random_bytes')) {
            $data = random_bytes(16);
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $data = openssl_random_pseudo_bytes(16);
        } else {
            $data = '';
            for ($i = 0; $i < 16; $i++) {
                $data .= chr(mt_rand(0, 255));
            }
        }

        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
