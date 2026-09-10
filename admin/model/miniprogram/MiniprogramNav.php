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

namespace Dou\Admin\Model\Miniprogram;

use Dou\Core\Orm\Model;

use Dou\Core\Facade\DB;
if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台小程序导航（nav 表中小程序相关 type）
 */
class MiniprogramNav extends Model
{
    /**
     * @var string
     */
    protected $table = 'nav';

    /**
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
     * @param string $type
     * @return int
     */
    public static function countByType($type)
    {
        return (int) DB::table(static::tableName())->where('type', $type)->count();
    }

    /**
     * @param int $id
     * @param string $fields
     * @return \Dou\Core\Orm\Model|null
     */
    public static function findMiniprogramNav($id, $fields = '*')
    {
        $model = static::find($id, $fields);
        if (!$model) {
            return null;
        }
        if (isset($model['type'])) {
            $navType = $model['type'];
        } else {
            $navType = static::getTypeById($id);
            if ($navType === null || $navType === false || $navType === '') {
                return null;
            }
        }
        if (strpos($navType, 'miniprogram_') !== 0) {
            return null;
        }

        return $model;
    }

    /**
     * @param int $id
     * @return string|null
     */
    public static function getTypeById($id)
    {
        return DB::table(static::tableName())->where('id', (int) $id)->value('type');
    }
}
