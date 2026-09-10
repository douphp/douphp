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

namespace Dou\Core\Model\Concerns;

use Dou\Core\Facade\DB;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 模块字段去重终结型静态方法：distinctValues() / classList()。
 *
 * 直返成品数组（不返回 Builder）；字段不存在时返回空数组（不抛错）。
 */
trait HasDistinctValues
{
    /**
     * 宿主必须是 Model 子类（提供表名）。
     *
     * @return string
     */
    abstract public function getTable();

    /**
     * 取本表某字段的去重值集合；可附带「当前值」用于前端高亮。
     *
     * @param string $field
     * @param string $currentValue 当前选中值
     * @param bool $oneLevel true：仅返回扁平字符串数组
     * @param string $where 原生 WHERE 片段（不含 WHERE 关键字）
     * @return array
     */
    public static function distinctValues($field, $currentValue = '', $oneLevel = false, $where = '')
    {
        $module = (new static())->getTable();
        if (!DB::fieldExist($module, $field)) {
            return array();
        }

        if ($where) {
            $sql = 'SELECT `' . DB::escapeString($field) . '` FROM ' . DB::tableName($module) . ' WHERE ' . $where;
            $query = DB::query($sql);
            $values = array();
            if ($query !== false) {
                while ($row = DB::fetchArray($query)) {
                    $values[] = $row[$field];
                }
            }
            $options = array_filter(array_unique($values));
        } else {
            $rows = DB::table($module)
                ->field($field)
                ->select();
            $values = array_column((array) $rows, $field);
            $options = array_filter(array_unique($values));
        }

        if ($oneLevel) {
            return $options;
        }

        $result = array();
        foreach ($options as $value) {
            $result[] = array(
                'value' => $value,
                'cur' => $currentValue ? ($currentValue == $value ? true : false) : false,
            );
        }

        return $result;
    }

    /**
     * 本表 class 字段去重列表 + 跳转 URL。
     *
     * @param string $class 当前选中 class（高亮）
     * @return array
     */
    public static function classList($class = '')
    {
        $module = (new static())->getTable();
        $noRepeat = static::distinctValues('class', $class);
        $result = array();
        foreach ((array) $noRepeat as $row) {
            $result[] = array(
                'name' => $row['value'],
                'cur' => $row['cur'],
                'url' => route($module . '.class', array('class' => $row['value'])),
            );
        }

        return $result;
    }
}
