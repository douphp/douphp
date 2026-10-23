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
 * 汉字转拼音 slug 纯工具。
 *
 * 数据源为 core/support/PinyinData.php（构建期由 devtools/pinyin-table-build.php 用 ICU 生成），
 * 运行时不依赖 intl / mbstring 扩展；仅用于生成扩展字段 slug 等内部标识。
 *
 * 约定：slug 只保证「合法 + 稳定 + 模块内唯一」，多音字取单字常用读音，不追求发音正确。
 */
class Pinyin
{
    /** @var array|null 汉字 => 拼音（首次使用时解析） */
    private static $map = null;

    /**
     * 文本转 slug：汉字取拼音、ASCII 字母数字保留、其余字符当分隔符。
     *
     * 任一汉字无法映射或结果为空时返回空串，由调用方兜底命名（如 field_2），
     * 避免产出「顏色 -> se」这类残缺且易误导的标识。
     *
     * @param string $text
     * @param int $maxLength 最大长度（上限 30，与 MetadataSchema::SLUG_PATTERN 对齐）
     * @return string
     */
    public static function slug($text, $maxLength = 30)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }

        $maxLength = (int) $maxLength;
        $maxLength = ($maxLength > 0 && $maxLength < 30) ? $maxLength : 30;

        $chars = self::chars($text);
        if (empty($chars)) {
            return '';
        }

        $map = self::map();
        $slug = '';
        $hanCount = 0;
        $hanMapped = 0;

        foreach ($chars as $char) {
            if (preg_match('/^[0-9a-zA-Z]$/', $char)) {
                $slug .= strtolower($char);
            } elseif (isset($map[$char])) {
                $hanCount++;
                $hanMapped++;
                $slug .= '_' . $map[$char];
            } elseif (self::isHan($char)) {
                $hanCount++;
            } else {
                $slug .= '_';
            }
        }

        if ($hanCount > 0 && $hanMapped < $hanCount) {
            return '';
        }

        $slug = trim(preg_replace('/_+/', '_', $slug), '_');
        if ($slug === '') {
            return '';
        }
        if (!preg_match('/^[a-z]/', $slug)) {
            $slug = 'f_' . $slug;
        }
        if (strlen($slug) > $maxLength) {
            $slug = rtrim(substr($slug, 0, $maxLength), '_');
        }
        if ($slug === '' || !preg_match('/^[a-z][a-z0-9_]{0,29}$/', $slug)) {
            return '';
        }

        return $slug;
    }

    /**
     * 汉字 => 拼音映射表。
     *
     * @return array
     */
    public static function map()
    {
        if (self::$map !== null) {
            return self::$map;
        }

        self::$map = array();
        $file = __DIR__ . '/PinyinData.php';
        $data = is_file($file) ? include $file : '';
        if (!is_string($data) || $data === '') {
            return self::$map;
        }

        $data = str_replace(array("\r\n", "\r"), "\n", $data);
        foreach (explode("\n", $data) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // 行格式「汉字拼音」：汉字在前、拼音为纯小写字母，按首个字母切分
            $offset = strcspn($line, 'abcdefghijklmnopqrstuvwxyz');
            if ($offset <= 0 || !isset($line[$offset])) {
                continue;
            }
            $char = substr($line, 0, $offset);
            $pinyin = substr($line, $offset);
            if ($pinyin !== '' && preg_match('/^[a-z]+$/', $pinyin)) {
                self::$map[$char] = $pinyin;
            }
        }

        return self::$map;
    }

    /**
     * 按 UTF-8 字符拆分；非法编码返回空数组。
     *
     * @param string $text
     * @return array
     */
    private static function chars($text)
    {
        $chars = array();
        if (preg_match_all('/./u', $text, $matches) === false) {
            return $chars;
        }

        return isset($matches[0]) ? $matches[0] : $chars;
    }

    /**
     * @param string $char
     * @return bool
     */
    private static function isHan($char)
    {
        return preg_match('/^\p{Han}$/u', $char) === 1;
    }
}
