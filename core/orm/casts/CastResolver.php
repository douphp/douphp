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

namespace Dou\Core\Orm\Casts;

use Dou\Core\Infra\Log\Log;
use Dou\Core\Orm\Model;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 字段类型转换器：在 getAttribute / toArray 读取时按声明把原始值转成模板/业务就绪形态。
 *
 * 内置：int/bool/float/string/json/array；业务：datetime[:fmt]、date[:fmt]、timestamp、
 * attachment / attachment_thumb、defined_pairs、data_lang:prefix_。业务侧可 register() 扩展。
 *
 * datetime/date/timestamp 读取兼容 int 时间戳与 DATETIME 字符串双态（时间字段统一过渡期）；
 * set_datetime 写入统一产出 'Y-m-d H:i:s'（DATETIME 存储态），空值 → null。
 */
class CastResolver
{
    /** @var array name => callable($value, $param, Model|null) */
    protected static $custom = array();

    /**
     * 注册自定义 cast。
     *
     * @param string $name
     * @param callable $callback
     * @return void
     */
    public static function register($name, $callback)
    {
        self::$custom[$name] = $callback;
    }

    /**
     * 应用 cast。
     *
     * @param string $type 形如 'datetime' 或 'datetime:Y-m-d' / 'data_lang:status_'
     * @param mixed $value
     * @param Model|null $model
     * @return mixed
     */
    public static function apply($type, $value, $model = null)
    {
        list($type, $param) = self::splitCastType($type);

        if (isset(self::$custom[$type])) {
            return call_user_func(self::$custom[$type], $value, $param, $model);
        }

        switch ($type) {
            case 'int':
                return ($value === null || $value === '') ? null : (int) $value;
            case 'float':
                return ($value === null || $value === '') ? null : (float) $value;
            case 'bool':
                return (bool) $value;
            case 'string':
                return $value === null ? null : (string) $value;
            case 'json':
            case 'array':
                if ($value === null || $value === '') {
                    return array();
                }
                $decoded = json_decode($value, true);
                if (!is_array($decoded)) {
                    // Log::write() 经 initPath() 使用 STORAGE_PATH，bootstrap 早期未定义时跳过。
                    if (defined('STORAGE_PATH')) {
                        Log::warning('CastResolver: json_decode failed', array('channel' => 'system', 'type' => gettype($value)));
                    }
                    return array();
                }
                return $decoded;
                // ----- 写入方向（applySet）专用分支 -----
            case 'set_int':
                return ($value === null || $value === '') ? 0 : (int) $value;
            case 'set_float':
                return ($value === null || $value === '') ? 0 : (float) $value;
            case 'set_bool':
                return (int) (bool) $value;
            case 'set_string':
                return $value === null ? '' : (string) $value;
            case 'set_json':
                if (is_array($value)) {
                    return json_encode($value);
                }
                return $value === null ? '' : (string) $value;
            case 'set_datetime':
                // 统一产出 'Y-m-d H:i:s' 存储态（DATETIME 列）；null/''/无效值 → null
                if ($value === null || $value === '') {
                    return null;
                }
                $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
                return $ts ? date('Y-m-d H:i:s', $ts) : null;
            case 'datetime':
                $ts = self::toTimestamp($value);
                return $ts === null ? '' : date($param ? $param : 'Y-m-d H:i', $ts);
            case 'date':
                $ts = self::toTimestamp($value);
                return $ts === null ? '' : date($param ? $param : 'Y-m-d', $ts);
            case 'timestamp':
                $ts = self::toTimestamp($value);
                if ($ts === null) {
                    return array('date' => '', 'parts' => array());
                }
                return array(
                    'date' => date('Y-m-d', $ts),
                    'parts' => Util::toDateParts($ts),
                );
            case 'attachment':
                return attachment()->url((string) $value);
            case 'attachment_thumb':
                return attachment()->url((string) $value, true);
            case 'defined_pairs':
                return Util::parseDefinedPairs($value);
            case 'data_lang':
                $language = language();
                return $language !== null ? $language->dataLangFormat($param, $value) : $value;
            default:
                return $value;
        }
    }

    /**
     * 写入方向转换：仅对有明确逆变换的 cast 反向转换，纯展示 cast 原样返回。
     *
     * 关键：避免对「已是存储态」的值二次转换。attachment / attachment_thumb /
     * defined_pairs / data_lang / timestamp 等纯展示 cast 直接返回原值（不反向）。
     *
     * @param string $type 形如 'int' / 'datetime' / 'datetime:Y-m-d'
     * @param mixed $value
     * @param Model|null $model
     * @return mixed
     */
    public static function applySet($type, $value, $model = null)
    {
        list($type) = self::splitCastType($type);

        switch ($type) {
            case 'int':
                return self::apply('set_int', $value, $model);
            case 'float':
                return self::apply('set_float', $value, $model);
            case 'bool':
                return self::apply('set_bool', $value, $model);
            case 'string':
                return self::apply('set_string', $value, $model);
            case 'json':
            case 'array':
                return self::apply('set_json', $value, $model);
            case 'datetime':
            case 'date':
                return self::apply('set_datetime', $value, $model);
            default:
                // attachment / attachment_thumb / defined_pairs / data_lang / timestamp / 自定义：不反向
                return $value;
        }
    }

    /**
     * 拆分 cast 类型与参数：'datetime:Y-m-d' → array('datetime', 'Y-m-d')，无冒号则参数为 null。
     *
     * @param string $type
     * @return array{0:string,1:string|null}
     */
    private static function splitCastType($type)
    {
        if (strpos($type, ':') === false) {
            return array($type, null);
        }
        $parts = explode(':', $type, 2);

        return array($parts[0], $parts[1]);
    }

    /**
     * 读取方向统一转 Unix 时间戳：兼容 int 时间戳与 DATETIME 字符串（'Y-m-d H:i:s'）双态。
     *
     * 过渡期说明：时间字段统一迁移后 DB 为 DATETIME，int 分支仅为兼容升级窗口期残留数据。
     *
     * @param mixed $value
     * @return int|null 无效/空值返回 null
     */
    private static function toTimestamp($value)
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value > 0 ? (int) $value : null;
        }
        $ts = strtotime((string) $value);

        return $ts === false ? null : $ts;
    }
}
