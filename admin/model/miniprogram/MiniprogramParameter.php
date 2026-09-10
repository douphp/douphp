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
 * 后台小程序参数（parameter 表 group=miniprogram）
 */
class MiniprogramParameter extends Model
{
    /**
     * @var string
     */
    protected $table = 'parameter';

    /**
     * @var array
     */
    protected $fillable = array(
        'value',
    );

    /**
     * 小程序参数行列表（与后台原排序一致）
     *
     * @return array
     */
    public static function listMiniprogramGroup()
    {
        return static::where('group', 'miniprogram')
            ->order('group DESC, sort ASC, id ASC')
            ->get();
    }

    /**
     * 仅更新白名单 name 对应的 value（且须为 miniprogram 分组）
     *
     * @param array $valuesByName name => value
     * @param array $allowedNames
     * @return void
     */
    public static function updateMiniprogramValues(array $valuesByName, array $allowedNames)
    {
        $allowed = array_flip($allowedNames);
        foreach ($valuesByName as $name => $value) {
            if (!isset($allowed[$name])) {
                continue;
            }
            static::where('name', $name)
                ->where('group', 'miniprogram')
                ->update(array('value' => $value));
        }
    }
}
