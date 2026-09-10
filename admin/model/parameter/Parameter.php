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

namespace Dou\Admin\Model\Parameter;

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台自定义参数（parameter 表）
 *
 * $fillable 与 ParameterFormRequest 分工同 ArticleModel。
 */
class Parameter extends Model
{
    /**
     * @var string
     */
    protected $table = 'parameter';

    /**
     * 允许批量写入的字段（持久化层）。
     *
     * @var array
     */
    protected $fillable = array(
        'name',
        'lang',
        'cue',
        'group',
        'sort',
        'value',
    );

    /**
     * 列表页：可选分组，sort ASC, id ASC。
     *
     * @param string $group 已规范的分组（空串表示全部）
     * @return array
     */
    public static function fetchListForIndex($group)
    {
        $query = static::query();
        if ($group !== '') {
            $query->where('group', $group);
        }

        return $query->order('sort ASC, id ASC')->get();
    }

    /**
     * 参数值设置页：可选分组，group DESC, sort ASC, id ASC。
     *
     * @param string $group 已规范的分组（空串表示全部）
     * @return array
     */
    public static function fetchListForSet($group)
    {
        $query = static::query();
        if ($group !== '') {
            $query->where('group', $group);
        }

        return $query->order('group DESC, sort ASC, id ASC')->get();
    }

    /**
     * 参数值保存（save）白名单：当前表单范围内允许的 name 列表。
     *
     * @param string $group 与设置页一致（空串表示全部）
     * @return array
     */
    public static function fetchNamesForSetWhitelist($group)
    {
        $query = DB::table(static::tableName())->field('name');
        if ($group !== '') {
            $query->where('group', $group);
        }

        return $query->column('name');
    }

    /**
     * 是否存在同名参数。
     *
     * @param string $name
     * @return bool
     */
    public static function existsByName($name)
    {
        $id = DB::table(static::tableName())->where('name', $name)->value('id');

        return (bool) $id;
    }

    /**
     * 是否存在同名参数且不是指定 id。
     *
     * @param string $name
     * @param int $exceptId
     * @return bool
     */
    public static function existsByNameExcept($name, $exceptId)
    {
        $row = static::where('name', $name)->first();
        if ($row === null) {
            return false;
        }

        return (int) $row['id'] !== (int) $exceptId;
    }

    /**
     * 读取某条记录的 name（用于更新前比对）。
     *
     * @param int $id
     * @return string|null
     */
    public static function getNameById($id)
    {
        $name = DB::table(static::tableName())->where('id', (int) $id)->value('name');

        return $name !== null && $name !== false ? (string) $name : null;
    }

    /**
     * 删除前读取 name、lock。
     *
     * @param int $id
     * @return \Dou\Core\Orm\Model|null
     */
    public static function findMetaForDelete($id)
    {
        $row = static::field('name, `lock`')->where('id', (int) $id)->first();
        if ($row === null) {
            return null;
        }

        return $row;
    }

    /**
     * 按 name 更新 value。
     *
     * @param string $name
     * @param string $value
     * @return mixed
     */
    public static function updateValueByName($name, $value)
    {
        return static::where('name', $name)->update(array('value' => $value));
    }

    /**
     * 删除未锁定记录。
     *
     * @param int $id
     * @return mixed
     */
    public static function deleteUnlocked($id)
    {
        return DB::table(static::tableName())->where('id', (int) $id)->where('lock', '0')->delete();
    }
}
