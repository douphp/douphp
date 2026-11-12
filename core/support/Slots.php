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

use Dou\Core\Foundation\Exception\DomainException;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 分区文本（slots）编解码与校验。
 *
 * 存储形态为 JSON 对象 {"标识":"内容"}，标识限 ASCII（模板 `{$data.x.slots.标识}` 中
 * 点号后只接受 \w）。data / show 两个模块共用本类的读写逻辑，保证主表与多语言覆盖
 * （language_value.field='slots'，type=json）使用同一套规则。
 */
class Slots
{
    /**
     * 单条记录允许的最大分区行数。
     */
    const MAX_ROWS = 50;

    /**
     * 单个分区内容的长度上限（按字符计）。
     */
    const MAX_VALUE_LENGTH = 5000;

    /**
     * 分区标识规则：小写字母开头，仅含小写字母、数字、下划线，总长 1~60。
     */
    const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,59}$/';

    /**
     * 解码为 map，非法输入一律返回空数组。
     *
     * @param mixed $json 主表读取的 JSON 串
     * @return array
     */
    public static function decode($json = '')
    {
        if (is_array($json)) {
            $raw = $json;
        } elseif (is_string($json) && trim($json) !== '') {
            $raw = json_decode($json, true);
        } else {
            return array();
        }

        if (!is_array($raw)) {
            return array();
        }

        // 只保留「字符串键 + 标量值」的项，数组/对象值视为脏数据丢弃
        $map = array();
        foreach ($raw as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $map[$key] = (string) $value;
        }

        return $map;
    }

    /**
     * 编码为 JSON 串，空 map 落库为空串。
     *
     * 保留空字符串值：主表需保留「已声明但未填内容」的占位行。
     *
     * @param array $map
     * @return string
     */
    public static function encode(array $map = array())
    {
        if ($map === array()) {
            return '';
        }

        $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '';
    }

    /**
     * 转为后台编辑用的行列表，保持 JSON 内的键顺序。
     *
     * @param mixed $json
     * @return array array(array('key' => '..', 'value' => '..'), ...)
     */
    public static function toRows($json = '')
    {
        $rows = array();
        foreach (self::decode($json) as $key => $value) {
            $rows[] = array('key' => $key, 'value' => $value);
        }

        return $rows;
    }

    /**
     * 覆盖式合并：默认 map 为基底，覆盖值仅取默认已有的键且非空。
     *
     * 用于多语言覆盖（language_value 只存被翻译的槽位），避免译文包带出多余键，
     * 也避免空译文把默认内容冲成空白。
     *
     * @param mixed $defaultJson 主表 JSON
     * @param mixed $overrideJson 多语言覆盖 JSON
     * @return array
     */
    public static function merge($defaultJson = '', $overrideJson = '')
    {
        $default = self::decode($defaultJson);
        if ($default === array()) {
            return array();
        }

        $override = self::decode($overrideJson);
        foreach ($default as $key => $value) {
            if (isset($override[$key]) && trim($override[$key]) !== '') {
                $default[$key] = $override[$key];
            }
        }

        return $default;
    }

    /**
     * 分区数量（列表徽标 / 只读展示）。
     *
     * @param mixed $json
     * @return int
     */
    public static function count($json = '')
    {
        return count(self::decode($json));
    }

    /**
     * 是否至少声明了一个分区（编辑页据此判定 slots 模式）。
     *
     * @param mixed $json
     * @return bool
     */
    public static function hasKeys($json = '')
    {
        return self::count($json) > 0;
    }

    /**
     * 标识归一化：去空白 + 转小写。
     *
     * @param mixed $key
     * @return string
     */
    public static function normalizeKey($key = '')
    {
        return strtolower(trim((string) $key));
    }

    /**
     * 标识是否合法。
     *
     * @param mixed $key
     * @return bool
     */
    public static function isValidKey($key = '')
    {
        return preg_match(self::KEY_PATTERN, (string) $key) === 1;
    }

    /**
     * 由后台成对提交的 slot_key[] / slot_value[] 组装 JSON。
     *
     * 规则：空键 + 空值视为空行忽略；空键有值、标识非法、标识重复、行数超限、
     * 内容超长均抛 DomainException（消息取自语言包）。
     *
     * @param mixed $keys slot_key[] 提交值
     * @param mixed $values slot_value[] 提交值
     * @return string
     */
    public static function fromRows($keys = array(), $values = array())
    {
        $keys = is_array($keys) ? array_values($keys) : array();
        $values = is_array($values) ? array_values($values) : array();
        $total = max(count($keys), count($values));

        $map = array();
        for ($i = 0; $i < $total; $i++) {
            $key = isset($keys[$i]) ? self::normalizeKey($keys[$i]) : '';
            $value = isset($values[$i]) ? (string) $values[$i] : '';

            if ($key === '' && trim($value) === '') {
                continue;
            }
            if ($key === '') {
                throw new DomainException(lang('slots_key_required', '存在未填写标识的分区，请补充标识或删除该行'));
            }
            if (!self::isValidKey($key)) {
                throw new DomainException(lang('slots_key_invalid', '分区标识只能使用小写字母、数字和下划线，且以字母开头') . '：' . $key);
            }
            if (isset($map[$key])) {
                throw new DomainException(lang('slots_key_duplicated', '分区标识不可重复') . '：' . $key);
            }
            if (Str::length($value) > self::MAX_VALUE_LENGTH) {
                throw new DomainException(lang('slots_value_too_long', '单个分区内容超长') . '：' . $key);
            }

            $map[$key] = $value;
            if (count($map) > self::MAX_ROWS) {
                throw new DomainException(lang('slots_too_many', '分区数量超出上限') . '：' . self::MAX_ROWS);
            }
        }

        return self::encode($map);
    }
}
