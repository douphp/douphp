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
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 单页 page 树终结型静态方法：pageTree() / pageNolevel()。
 *
 * 直返成品数组（不返回 Builder），page 表全部行带进程内缓存与多语言预热。
 */
trait HasPageTree
{
    /**
     * 单页嵌套树（含 child / cur / 多语言）。
     *
     * @param int $parentId
     * @param string|int $currentId
     * @return array
     */
    public static function pageTree($parentId = 0, $currentId = '')
    {
        $pageList = array();
        $data = self::pageRows();

        foreach ((array) $data as $value) {
            if (!is_array($value)) {
                continue;
            }
            $value = language()->langBox($value, 'page', 'name');

            if ($value['parent_id'] == $parentId) {
                $value['url'] = route('page.show', array('id' => $value['id']));
                $value['cur'] = $value['id'] == $currentId ? true : false;

                $hasChild = false;
                foreach ($data as $rowChild) {
                    if (!is_array($rowChild)) {
                        continue;
                    }
                    if ($rowChild['parent_id'] == $value['id']) {
                        $hasChild = true;
                        break;
                    }
                }
                $value['child'] = $hasChild ? self::pageTree($value['id'], $currentId) : array();

                $pageList[] = $value;
            }
        }

        return $pageList;
    }

    /**
     * 单页树无层级缩进列表（前缀 $mark 重复表示层级）。
     *
     * @param int $parentId
     * @param int $level
     * @param string|int $currentId 排除的单页 id（父级下拉）
     * @param array $acc 累计输出
     * @param string $mark 缩进符
     * @return array
     */
    public static function pageNolevel($parentId = 0, $level = 0, $currentId = '', array &$acc = array(), $mark = ' - ')
    {
        $data = self::pageRows();

        foreach ((array) $data as $value) {
            if (!is_array($value)) {
                continue;
            }
            if ($value['parent_id'] == $parentId && $value['id'] != $currentId) {
                $item = array(
                    'id' => $value['id'],
                    'parent_id' => $value['parent_id'],
                    'slug' => isset($value['slug']) ? $value['slug'] : '',
                    'name' => $value['name'],
                    'description' => $value['description'] ? $value['description'] : Str::excerpt($value['content'], 320),
                    'sort' => isset($value['sort']) ? $value['sort'] : 0,
                    'url' => route('page.show', array('id' => $value['id'])),
                    'mark' => str_repeat($mark, $level),
                    'level' => $level,
                );

                $lang = language()->langBox($value, 'page', 'name');
                if (isset($lang['name'])) {
                    $item['name'] = $lang['name'];
                }

                $acc[] = $item;

                self::pageNolevel($value['id'], $level + 1, $currentId, $acc, $mark);
            }
        }

        return $acc;
    }

    /**
     * page 表全部行（进程内静态缓存）；预热 name 多语言。
     *
     * @return array
     */
    private static function pageRows()
    {
        static $cache = null;
        if ($cache === null) {
            $cache = DB::table('page')
                ->field('*')
                ->order('id ASC')
                ->select();
            if ($cache === false) {
                $cache = array();
            }
            language()->warmup('page', array_column((array) $cache, 'id'), 'name');
        }

        return $cache;
    }
}
