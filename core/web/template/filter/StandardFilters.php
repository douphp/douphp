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

namespace Dou\Core\Web\Template\Filter;

use Dou\Core\Web\Template\FilterRegistry;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 标准修饰器集合（算法迁移自旧 Smarty 的 smartyModifier* 系列）。
 *
 * 全部为纯静态函数，按名注册到 {@see FilterRegistry}，运行期由 {@see \Dou\Core\Web\Template\RenderContext::filter()} 分发。
 */
class StandardFilters
{
    /**
     * 把标准修饰器注册进给定注册表。
     *
     * @param FilterRegistry $registry
     * @return void
     */
    public static function registerInto(FilterRegistry $registry)
    {
        $map = array(
            'truncate' => array(__CLASS__, 'truncate'),
            'escape' => array(__CLASS__, 'escape'),
            'nl2br' => array(__CLASS__, 'nl2br'),
            'strip_tags' => array(__CLASS__, 'stripTags'),
            'date_format' => array(__CLASS__, 'dateFormat'),
            'default' => array(__CLASS__, 'defaultValue'),
            'indent' => array(__CLASS__, 'indent'),
            'string_format' => array(__CLASS__, 'stringFormat'),
            'strip' => array(__CLASS__, 'strip'),
            'capitalize' => array(__CLASS__, 'capitalize'),
            'cat' => array(__CLASS__, 'cat'),
            'count_characters' => array(__CLASS__, 'countCharacters'),
            'count_paragraphs' => array(__CLASS__, 'countParagraphs'),
            'count_sentences' => array(__CLASS__, 'countSentences'),
            'count_words' => array(__CLASS__, 'countWords'),
            'lower' => array(__CLASS__, 'lower'),
            'upper' => array(__CLASS__, 'upper'),
            'replace' => array(__CLASS__, 'replace'),
            'spacify' => array(__CLASS__, 'spacify'),
            'wordwrap' => array(__CLASS__, 'wordwrap'),
        );
        foreach ($map as $name => $callable) {
            $registry->register($name, $callable);
        }
    }

    /**
     * truncate：截断字符串（UTF-8 安全）。
     *
     * @param string $string
     * @param int $length
     * @param string $etc
     * @param bool $break_words
     * @param bool $middle
     * @return string
     */
    public static function truncate($string, $length = 80, $etc = '...', $break_words = false, $middle = false)
    {
        if ($length == 0) {
            return '';
        }
        if (self::strlenUtf8($string) > $length) {
            $length -= min($length, self::strlenUtf8($etc));
            if (!$break_words && !$middle) {
                $string = preg_replace('/\s+?(\S+)?$/', '', self::substrUtf8($string, 0, $length + 1));
            }
            if (!$middle) {
                return self::substrUtf8($string, 0, $length) . $etc;
            }
            $half = (int) ($length / 2);
            return self::substrUtf8($string, 0, $half) . $etc . self::substrUtf8($string, self::strlenUtf8($string) - $half, $half);
        }

        return $string;
    }

    /**
     * escape：多类型转义。
     *
     * @param string $string
     * @param string $esc_type
     * @param string $char_set
     * @return string
     */
    public static function escape($string, $esc_type = 'html', $char_set = 'UTF-8')
    {
        switch ($esc_type) {
            case 'html':
                return htmlspecialchars((string) $string, ENT_QUOTES, $char_set);

            case 'htmlall':
                return htmlentities((string) $string, ENT_QUOTES, $char_set);

            case 'url':
                return rawurlencode((string) $string);

            case 'urlpathinfo':
                return str_replace('%2F', '/', rawurlencode((string) $string));

            case 'quotes':
                return preg_replace("%(?<!\\\\)'%", "\\'", $string);

            case 'hex':
                $return = '';
                for ($x = 0, $len = strlen($string); $x < $len; $x++) {
                    $return .= '%' . bin2hex($string[$x]);
                }
                return $return;

            case 'hexentity':
                $return = '';
                for ($x = 0, $len = strlen($string); $x < $len; $x++) {
                    $return .= '&#x' . bin2hex($string[$x]) . ';';
                }
                return $return;

            case 'decentity':
                $return = '';
                for ($x = 0, $len = strlen($string); $x < $len; $x++) {
                    $return .= '&#' . ord($string[$x]) . ';';
                }
                return $return;

            case 'javascript':
                return strtr($string, array('\\' => '\\\\', "'" => "\\'", '"' => '\\"', "\r" => '\\r', "\n" => '\\n', '</' => '<\/'));

            case 'mail':
                return str_replace(array('@', '.'), array(' [AT] ', ' [DOT] '), $string);

            case 'nonstd':
                $_res = '';
                for ($_i = 0, $_len = strlen($string); $_i < $_len; $_i++) {
                    $_ord = ord(substr($string, $_i, 1));
                    if ($_ord >= 126) {
                        $_res .= '&#' . $_ord . ';';
                    } else {
                        $_res .= substr($string, $_i, 1);
                    }
                }
                return $_res;

            default:
                return $string;
        }
    }

    /**
     * nl2br。
     *
     * @param string $string
     * @return string
     */
    public static function nl2br($string)
    {
        return nl2br((string) $string);
    }

    /**
     * strip_tags：去除 HTML 标签。
     *
     * @param string $string
     * @param bool $replace_with_space
     * @return string
     */
    public static function stripTags($string, $replace_with_space = true)
    {
        if ($replace_with_space) {
            return preg_replace('!<[^>]*?>!', ' ', $string);
        }

        return strip_tags($string);
    }

    /**
     * date_format：日期格式化（strftime 语义；PHP 8.1+ 走 date 映射）。
     *
     * @param string $string
     * @param string $format
     * @param string $default_date
     * @return string|null
     */
    public static function dateFormat($string, $format = '%b %e, %Y', $default_date = '')
    {
        if ($string != '') {
            $timestamp = self::makeTimestamp($string);
        } elseif ($default_date != '') {
            $timestamp = self::makeTimestamp($default_date);
        } else {
            return null;
        }

        if (PHP_VERSION_ID >= 80100) {
            return self::dateFormatPhp8($format, $timestamp);
        }

        if (DIRECTORY_SEPARATOR == '\\') {
            $_win_from = array('%D', '%h', '%n', '%r', '%R', '%t', '%T');
            $_win_to = array('%m/%d/%y', '%b', "\n", '%I:%M:%S %p', '%H:%M', "\t", '%H:%M:%S');
            if (strpos($format, '%e') !== false) {
                $_win_from[] = '%e';
                $_win_to[] = sprintf('%\' 2d', date('j', $timestamp));
            }
            if (strpos($format, '%l') !== false) {
                $_win_from[] = '%l';
                $_win_to[] = sprintf('%\' 2d', date('h', $timestamp));
            }
            $format = str_replace($_win_from, $_win_to, $format);
        }

        return strftime($format, $timestamp);
    }

    /**
     * default：变量为空时回退默认值。
     *
     * @param mixed $string
     * @param string $default
     * @return mixed
     */
    public static function defaultValue($string, $default = '')
    {
        if (!isset($string) || $string === '') {
            return $default;
        }

        return $string;
    }

    /**
     * indent：每行缩进。
     *
     * @param string $string
     * @param int $chars
     * @param string $char
     * @return string
     */
    public static function indent($string, $chars = 4, $char = ' ')
    {
        return preg_replace('!^!m', str_repeat($char, $chars), $string);
    }

    /**
     * string_format：sprintf 格式化。
     *
     * @param mixed $string
     * @param string $format
     * @return string
     */
    public static function stringFormat($string, $format)
    {
        return sprintf($format, $string);
    }

    /**
     * strip：多空白压缩为单字符。
     *
     * @param string $text
     * @param string $replace
     * @return string
     */
    public static function strip($text, $replace = ' ')
    {
        return preg_replace('!\s+!', $replace, $text);
    }

    /**
     * capitalize：首字母大写。
     *
     * @param string $string
     * @param bool $uc_digits
     * @return string
     */
    public static function capitalize($string, $uc_digits = false)
    {
        return preg_replace_callback('!\'?\b\w(\w|\')*\b!', function ($m) use ($uc_digits) {
            $word = $m[0];
            if (substr($word, 0, 1) != "'" && !preg_match('!\d!', $word) || $uc_digits) {
                return ucfirst($word);
            }
            return $word;
        }, $string);
    }

    /**
     * cat：字符串拼接。
     *
     * @param string $string
     * @param string $cat
     * @return string
     */
    public static function cat($string, $cat = '')
    {
        return $string . $cat;
    }

    /**
     * count_characters。
     *
     * @param string $string
     * @param bool $include_spaces
     * @return int
     */
    public static function countCharacters($string, $include_spaces = false)
    {
        if ($include_spaces) {
            return strlen($string);
        }

        return preg_match_all('/[^\s]/', $string, $match);
    }

    /**
     * count_paragraphs。
     *
     * @param string $string
     * @return int
     */
    public static function countParagraphs($string)
    {
        return count(preg_split('/[\r\n]+/', $string));
    }

    /**
     * count_sentences。
     *
     * @param string $string
     * @return int
     */
    public static function countSentences($string)
    {
        return preg_match_all('/[^\s]\.(?!\w)/', $string, $match);
    }

    /**
     * count_words。
     *
     * @param string $string
     * @return int
     */
    public static function countWords($string)
    {
        $split_array = preg_split('/\s+/', $string);
        $word_count = preg_grep('/[a-zA-Z0-9\\x80-\\xff]/', $split_array);

        return count($word_count);
    }

    /**
     * lower。
     *
     * @param string $string
     * @return string
     */
    public static function lower($string)
    {
        return strtolower($string);
    }

    /**
     * upper。
     *
     * @param string $string
     * @return string
     */
    public static function upper($string)
    {
        return strtoupper($string);
    }

    /**
     * replace。
     *
     * @param string $string
     * @param string $search
     * @param string $replace
     * @return string
     */
    public static function replace($string, $search, $replace)
    {
        return str_replace($search, $replace, $string);
    }

    /**
     * spacify。
     *
     * @param string $string
     * @param string $spacify_char
     * @return string
     */
    public static function spacify($string, $spacify_char = ' ')
    {
        return implode($spacify_char, preg_split('//', $string, -1, PREG_SPLIT_NO_EMPTY));
    }

    /**
     * wordwrap。
     *
     * @param string $string
     * @param int $length
     * @param string $break
     * @param bool $cut
     * @return string
     */
    public static function wordwrap($string, $length = 80, $break = "\n", $cut = false)
    {
        return wordwrap($string, $length, $break, $cut);
    }

    /**
     * UTF-8 安全的 mb_substr 封装（无 mbstring 时手动按字节宽度切）。
     *
     * @param string $str
     * @param int $start
     * @param int $len
     * @return string
     */
    private static function substrUtf8($str, $start, $len)
    {
        if (function_exists('mb_substr')) {
            return mb_substr($str, $start, $len, 'UTF-8');
        }

        $str_len = strlen($str);
        $char_count = 0;
        $i = 0;
        for (; $i < $str_len && $char_count < $start;) {
            $i += self::utf8CharSize(ord($str[$i]));
            $char_count++;
        }
        $byte_start = $i;
        $char_count = 0;
        for (; $i < $str_len && $char_count < $len;) {
            $i += self::utf8CharSize(ord($str[$i]));
            $char_count++;
        }

        return substr($str, $byte_start, $i - $byte_start);
    }

    /**
     * UTF-8 安全的 mb_strlen 封装。
     *
     * @param string $str
     * @return int
     */
    private static function strlenUtf8($str)
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($str, 'UTF-8');
        }

        $str_len = strlen($str);
        $char_count = 0;
        for ($i = 0; $i < $str_len;) {
            $i += self::utf8CharSize(ord($str[$i]));
            $char_count++;
        }

        return $char_count;
    }

    /**
     * UTF-8 首字节 → 字符字节数。
     *
     * @param int $byte
     * @return int
     */
    private static function utf8CharSize($byte)
    {
        if ($byte < 0x80) {
            return 1;
        }
        if ($byte < 0xC0) {
            return 1;
        }
        if ($byte < 0xE0) {
            return 2;
        }
        if ($byte < 0xF0) {
            return 3;
        }
        if ($byte < 0xF8) {
            return 4;
        }

        return 1;
    }

    /**
     * strftime → date 格式映射后取实际日期（PHP 8.1+ strftime 已废弃）。
     *
     * 把 strftime 占位符映射为 date() 占位符再交由 date() 求值，输出与 PHP 5.6–8.0
     * 的 strftime 路径语义一致（如 "%Y-%m-%d" → 实际日期 "2026-06-18"）。
     *
     * @param string $format
     * @param int $timestamp
     * @return string
     */
    private static function dateFormatPhp8($format, $timestamp)
    {
        $search = array('%Y', '%y', '%m', '%d', '%e', '%H', '%I', '%M', '%S', '%a', '%A', '%b', '%B', '%p', '%P', '%R', '%T', '%D', '%n', '%t', '%r', '%U', '%V', '%W', '%c', '%x', '%X', '%%');
        $replace = array('Y', 'y', 'm', 'd', 'j', 'H', 'h', 'i', 's', 'D', 'l', 'M', 'F', 'A', 'a', 'H:i', 'H:i:s', 'm/d/y', "\n", "\t", 'h:i:s A', 'W', 'W', 'W', 'Y-m-d H:i:s', 'Y-m-d', 'H:i:s', '%');

        if (strpos($format, '%e') !== false) {
            $format = str_replace('%e', date('j', $timestamp), $format);
            unset($search[4], $replace[4]);
        }
        if (strpos($format, '%l') !== false) {
            $format = str_replace('%l', ltrim(date('h', $timestamp), '0'), $format);
        }

        return date(str_replace($search, $replace, $format), $timestamp);
    }

    /**
     * 解析多种日期形态为时间戳。
     *
     * @param mixed $string
     * @return int
     */
    private static function makeTimestamp($string)
    {
        if (empty($string)) {
            return time();
        }
        if (preg_match('/^\d{14}$/', $string)) {
            return mktime(
                substr($string, 8, 2),
                substr($string, 10, 2),
                substr($string, 12, 2),
                substr($string, 4, 2),
                substr($string, 6, 2),
                substr($string, 0, 4)
            );
        }
        if (is_numeric($string)) {
            return (int) $string;
        }
        $time = strtotime($string);
        if ($time === -1 || $time === false) {
            return time();
        }

        return $time;
    }
}
