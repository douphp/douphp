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
 * 分类终结型静态方法：tree() / flat()。
 *
 * 直返成品数组（不返回 Builder），模块名由分类表名推导
 * （article_category -> article），URL 走 route($module . '.category')。
 */
trait HasCategoryTree
{
    /**
     * 宿主必须是 Model 子类（提供表名）。
     *
     * @return string
     */
    abstract public function getTable();

    /**
     * 分类嵌套树（含 child / cur / level / 多语言）。
     *
     * @param string|int $currentId 当前激活分类 id
     * @return array
     */
    public static function tree($currentId = 0)
    {
        $self = new static();

        return self::categoryTreeWalk($self->getTable(), 0, $currentId, 0);
    }

    /**
     * 分类无层级缩进列表（前缀 $mark 重复表示层级）。
     *
     * @param string|int $currentId 要排除的分类 id（父级下拉避免选中自身）
     * @param string $mark 缩进符
     * @return array
     */
    public static function flat($currentId = 0, $mark = '　')
    {
        $self = new static();
        $acc = array();
        self::categoryFlatWalk($self->getTable(), 0, 0, $currentId, $acc, $mark);

        return $acc;
    }

    /**
     * 表内全部分类行（进程内静态缓存，按表名）；预热 name / description 多语言。
     *
     * @param string $table
     * @return array
     */
    private static function categoryRows($table)
    {
        static $cache = array();
        if (!isset($cache[$table])) {
            $cache[$table] = DB::table($table)
                ->field('*')
                ->order('sort ASC, id ASC')
                ->select();
            language()->warmup($table, array_column((array) $cache[$table], 'id'), 'name, description');
        }

        return $cache[$table];
    }

    /**
     * @param string $table
     * @param int $parentId
     * @param string|int $currentId
     * @param int $level
     * @return array
     */
    private static function categoryTreeWalk($table, $parentId, $currentId, $level)
    {
        $data = self::categoryRows($table);
        $module = preg_replace('/_category$/', '', $table);
        $category = array();

        foreach ((array) $data as $value) {
            if (!is_array($value)) {
                continue;
            }
            $value = language()->langBox($value, $table, 'name, description');

            if ($value['parent_id'] == $parentId) {
                $value['url'] = route($module . '.category', array('category_id' => $value['id']));
                $value['icon'] = (isset($value['icon']) && $value['icon']) ? attachment()->url($value['icon']) : '';
                $value['cur'] = $value['id'] == $currentId ? true : false;
                $value['level'] = $level;
                $value['child'] = self::categoryTreeWalk($table, $value['id'], $currentId, $level + 1);

                $category[] = $value;
            }
        }

        return $category;
    }

    /**
     * @param string $table
     * @param int $parentId
     * @param int $level
     * @param string|int $currentId
     * @param array $acc
     * @param string $mark
     * @return void
     */
    private static function categoryFlatWalk($table, $parentId, $level, $currentId, array &$acc, $mark)
    {
        $data = self::categoryRows($table);
        $module = preg_replace('/_category$/', '', $table);

        foreach ((array) $data as $value) {
            if (!is_array($value)) {
                continue;
            }
            if ($value['parent_id'] == $parentId && $value['id'] != $currentId) {
                $acc[] = array(
                    'id' => $value['id'],
                    'slug' => $value['slug'],
                    'parent_id' => $value['parent_id'],
                    'name' => $value['name'],
                    'icon' => (isset($value['icon']) && $value['icon']) ? attachment()->url($value['icon']) : '',
                    'description' => $value['description'],
                    'sort' => $value['sort'],
                    'url' => route($module . '.category', array('category_id' => $value['id'])),
                    'mark' => str_repeat($mark, $level),
                    'level' => $level,
                );

                self::categoryFlatWalk($table, $value['id'], $level + 1, $currentId, $acc, $mark);
            }
        }
    }
}
