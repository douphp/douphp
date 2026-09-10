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

namespace Dou\Front\Model\Concerns;

use Dou\Core\Facade\DB;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 详情页相邻导航（previous / next）终结型静态门面 lift($id, $catId)。
 *
 * 模块名由宿主 Model 的 getTable() 推导；按 id 上下取同分类相邻条目，
 * 多语言经 langBox 覆写 title / name。
 */
trait HasContentLift
{
    /**
     * 宿主必须是 Model 子类（提供表名）。
     *
     * @return string
     */
    abstract public function getTable();

    /**
     * 同分类下相邻条目（按 id 上下）。
     *
     * @param int $id 当前记录 id
     * @param int|string $catId 当前分类 id，0/'' 时不按分类过滤
     * @return array previous / next 两键；缺失时对应为 null/false
     */
    public static function lift($id, $catId)
    {
        $module = (new static())->getTable();
        $field = DB::fieldExist($module, 'title') ? 'title' : 'name';

        $query = DB::table($module)
            ->field('id, description, image, ' . $field)
            ->where('id', '>', intval($id));
        if ($catId) {
            $query->where('category_id', intval($catId));
        }
        $lift = array();
        $lift['previous'] = $query->order('id ASC')->find();
        if ($lift['previous']) {
            $lift['previous']['url'] = route($module . '.show', array('id' => $lift['previous']['id']));
            $prevImage = $lift['previous']['image'];
            $lift['previous']['image'] = attachment()->url($prevImage);
            $lift['previous']['thumb'] = attachment()->url($prevImage, true);
            $lift['previous'] = language()->langBox($lift['previous'], $module, $field);
        }

        $query = DB::table($module)
            ->field('id, description, image, ' . $field)
            ->where('id', '<', intval($id));
        if ($catId) {
            $query->where('category_id', intval($catId));
        }
        $lift['next'] = $query->order('id DESC')->find();
        if ($lift['next']) {
            $lift['next']['url'] = route($module . '.show', array('id' => $lift['next']['id']));
            $nextImage = $lift['next']['image'];
            $lift['next']['image'] = attachment()->url($nextImage);
            $lift['next']['thumb'] = attachment()->url($nextImage, true);
            $lift['next'] = language()->langBox($lift['next'], $module, $field);
        }

        return $lift;
    }
}
