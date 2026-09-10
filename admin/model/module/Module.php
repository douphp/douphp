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

namespace Dou\Admin\Model\Module;

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台模块扩展数据访问（扩展包对应逻辑表名上的行数统计等）。
 *
 * 说明：本类不对应单一业务主表；$table 仅占位以满足 BaseModel 约定，勿使用继承的 insert/update。
 */
class Module extends Model
{
    /**
     * 占位主表（勿用于业务 CRUD；勿改为真实业务表名以免误用继承方法）。
     *
     * @var string
     */
    protected $table = 'dou_module_guard_unused';

    /**
     * 若逻辑表存在则返回其行数，否则返回 0。
     *
     * @param string $extendId 已通过调用方格式校验的扩展标识（逻辑表名）。
     * @return int
     */
    public static function countRowsIfTableExists($extendId)
    {
        if ($extendId === '') {
            return 0;
        }

        if (!DB::tableExist($extendId)) {
            return 0;
        }

        $row = DB::table($extendId)->field('COUNT(*) AS dou_row_cnt')->find();
        if (!$row || !isset($row['dou_row_cnt'])) {
            return 0;
        }

        return (int) $row['dou_row_cnt'];
    }
}
