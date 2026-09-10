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

namespace Dou\Admin\Model\Nav;

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台主导航（nav 表）
 *
 * $fillable 与 NavFormRequest 分工同 ArticleModel。
 */
class Nav extends Model
{
    /**
     * @var string
     */
    protected $table = 'nav';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'parent_id' => 'int',
        'sort' => 'int',
        'status' => 'data_lang:nav_status_',
    );

    /**
     * 允许批量写入的字段（持久化层）。
     *
     * @var array
     */
    protected $fillable = array(
        'module',
        'name',
        'icon',
        'guide',
        'parent_id',
        'type',
        'sort',
        'status',
    );

    /**
     * 全表按 sort 排序（AR Collection，用于后台树形展示）。
     *
     * @return \Dou\Core\Orm\Collection
     */
    public static function listAllOrdered()
    {
        return static::order('sort ASC')->get();
    }

    /**
     * 全表按 sort 排序（原始数组形态）。
     *
     * @return array
     */
    public static function fetchAllOrdered()
    {
        return static::order('sort ASC')->get();
    }

    /**
     * 是否存在以指定 id 为父级的子导航。
     *
     * @param int $parentId
     * @return bool
     */
    public static function hasChild($parentId)
    {
        $childId = DB::table(static::tableName())->where('parent_id', (int) $parentId)->value('id');

        return (bool) $childId;
    }

    /**
     * 读取某条导航的 parent_id。
     *
     * @param int $id
     * @return mixed
     */
    public static function getParentId($id)
    {
        return DB::table(static::tableName())->where('id', (int) $id)->value('parent_id');
    }
}
