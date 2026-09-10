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

namespace Dou\Admin\Model\Tool;

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台系统工具持久化（config 更新、动态表字段切换、正文批量 REPLACE）。
 *
 * @property string $table 名义归属表（config）
 */
class Tool extends Model
{
    /**
     * @var string
     */
    protected $table = 'config';

    /**
     */
    public function __construct()
    {
    }

    /**
     * 按 config.name 更新 value。
     *
     * @param string $name
     * @param string $value
     * @return void
     */
    public function updateConfigValueByName($name, $value)
    {
        DB::table('config')->where('name', $name)->update(array('value' => $value));
    }

    /**
     * @param string $module
     * @param int $itemId
     * @param string $field
     * @return mixed
     */
    public function getTableFieldValue($module, $itemId, $field)
    {
        return DB::table($module)->where('id', intval($itemId))->value($field);
    }

    /**
     * @param string $module
     * @param int $itemId
     * @param string $field
     * @param mixed $value
     * @return void
     */
    public function updateTableField($module, $itemId, $field, $value)
    {
        DB::table($module)->where('id', intval($itemId))->update(array($field => $value));
    }

    /**
     * 在栏目模型、单页模型及 page 表的 content 字段中批量 REPLACE 旧域名为新域名。
     *
     * `$old_url` / `$new_url` 经 setField 的 `$binds` 参数化绑定到 `replace(content, ?, ?)`，
     * 调用方传入原始值即可（不需也不应预先 SQL 转义），杜绝字符串拼接注入。
     *
     * @param string $old_url
     * @param string $new_url
     * @param array $columnModules
     * @param array $singleModules
     * @return void
     */
    public function replaceUrlInContentTables($old_url, $new_url, array $columnModules, array $singleModules)
    {
        $binds = array($old_url, $new_url);

        foreach ($columnModules as $module) {
            if (DB::tableExist($module)) {
                if (DB::fieldExist($module, 'content')) {
                    DB::table($module)->setField('content', 'replace(content, ?, ?)', true, $binds);
                }
            }
        }

        foreach ($singleModules as $module) {
            if (DB::tableExist($module)) {
                if (DB::fieldExist($module, 'content')) {
                    DB::table($module)->setField('content', 'replace(content, ?, ?)', true, $binds);
                }
            }
        }

        DB::table('page')->setField('content', 'replace(content, ?, ?)', true, $binds);
    }
}
