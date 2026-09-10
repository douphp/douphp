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
 * 后台小程序幻灯（show 表 type=miniprogram）
 */
class MiniprogramShow extends Model
{
    /**
     * @var string
     */
    protected $table = 'show';

    /**
     * @var array
     */
    protected $fillable = array(
        'name',
        'link',
        'image',
        'type',
        'sort',
    );

    /**
     * @param int    $id
     * @param string $fields
     * @return \Dou\Core\Orm\Model|null
     */
    public static function findMiniprogramShow($id, $fields = '*')
    {
        $model = static::find($id, $fields);
        if (!$model) {
            return null;
        }
        if (!isset($model['type']) || $model['type'] !== 'miniprogram') {
            return null;
        }

        return $model;
    }

    /**
     * @param int $id
     * @return bool
     */
    public static function existsMiniprogramShow($id)
    {
        return (bool) DB::table(static::tableName())
            ->where('id', (int) $id)
            ->where('type', 'miniprogram')
            ->value('id');
    }
}
