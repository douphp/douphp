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

namespace Dou\Admin\Model\Setting;

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台设置数据模型（config / parameter）。
 *
 * 持久化字段集合由运行时数据库中的 config.name、parameter.name 决定，
 * 与 {@see SettingFormRequest} 动态 rules 共用同一查询来源。
 *
 * @property string $table 名义主表（通用 CRUD 少用，主要为命名空间归属）
 */
class Setting extends Model
{
    /**
     * @var string
     */
    protected $table = 'config';

    /**
     * 供表单校验：config 表全部 name => 规则后缀（array 类型用 array 规则，其余为空串表示仅白名单）。
     *
     * @return array
     */
    public static function fetchAllConfigFieldRules()
    {
        $rows = DB::table('config')->field('name,type')->select();
        $rules = array();
        foreach ((array) $rows as $row) {
            $name = isset($row['name']) ? $row['name'] : '';
            if ($name === '') {
                continue;
            }
            $type = isset($row['type']) ? $row['type'] : '';
            $rules[$name] = ($type === 'array') ? 'array' : '';
        }

        return $rules;
    }

    /**
     * 供表单校验：parameter 表行对应 POST 键 _parameter_{name}。
     *
     * @return array
     */
    public static function fetchParameterPrefixedRules()
    {
        $rows = DB::table('parameter')->field('name')->select();
        $rules = array();
        foreach ((array) $rows as $row) {
            $name = isset($row['name']) ? $row['name'] : '';
            if ($name === '') {
                continue;
            }
            $rules['_parameter_' . $name] = '';
        }

        return $rules;
    }

    /**
     * 更新单个 config 项。
     *
     * @param string $name
     * @param string $value
     * @return void
     */
    public static function updateConfigValue($name, $value)
    {
        DB::table('config')->where('name', $name)->update(array('value' => $value));
    }

    /**
     * 按 config.name 读取单行（无唯一索引场景下的查询封装）。
     *
     * @param string $name
     * @return \Dou\Core\Orm\Model|null
     */
    public static function findRowByConfigName($name)
    {
        return static::where('name', $name)->first();
    }

    /**
     * 将已通过校验的白名单数据写入 config / parameter。
     *
     * @param array $data
     * @return void
     */
    public static function persistFromValidated(array $data)
    {
        foreach ($data as $name => $value) {
            if (strpos($name, '_parameter_') === 0) {
                $pname = str_replace('_parameter_', '', $name);
                DB::table('parameter')->where('name', $pname)->update(array('value' => $value));
            }

            $stored = $value;
            if (is_array($stored)) {
                $stored = serialize($stored);
            }

            DB::table('config')->where('name', $name)->update(array('value' => $stored));
        }
    }
}
