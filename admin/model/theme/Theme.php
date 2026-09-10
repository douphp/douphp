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

namespace Dou\Admin\Model\Theme;

use Dou\Core\Orm\Model;

use Dou\Core\Facade\DB;
if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台模板（主题）相关持久化：config、parameter、data。
 */
class Theme extends Model
{

    /**
     * 名义主表（主题跨多表，此处仅作命名空间归属）。
     *
     * @var string
     */
    protected $table = 'config';

    /**
     * parameter 表插入 theme_support_module 等占位行时用。
     *
     * @var array
     */
    protected $fillable = array(
        'name',
        'lang',
        'value',
        'cue',
        'group',
    );

    /**
     * 按 config.name 更新 value。
     *
     * @param string $name
     * @param string $value
     * @return void
     */
    public static function updateConfigValue($name, $value)
    {
        DB::table('config')->where('name', $name)->update(array('value' => $value));
    }

    /**
     * @param string $slug
     * @return array
     */
    public static function selectDataRowsByThemeOrdered($slug)
    {
        $rows = DB::table('data')->where('theme', $slug)->order('sort ASC, id ASC')->select();

        return is_array($rows) ? $rows : array();
    }

    /**
     * @param string $slug
     * @return void
     */
    public static function deleteDataByTheme($slug)
    {
        DB::table('data')->where('theme', $slug)->delete();
    }

    /**
     * @return bool 是否存在 theme_support_module 参数行
     */
    public static function supportModuleParameterExists()
    {
        return (bool) DB::table('parameter')->where('name', 'theme_support_module')->find();
    }

    /**
     * @return string|null
     */
    public static function getThemeSupportModuleValue()
    {
        return DB::table('parameter')->where('name', 'theme_support_module')->value('value');
    }

    /**
     * @param string $text
     * @return void
     */
    public static function updateThemeSupportModuleValue($text)
    {
        DB::table('parameter')->where('name', 'theme_support_module')->update(array('value' => $text));
    }

    /**
     * @return void
     */
    public static function clearThemeSupportModule()
    {
        DB::table('parameter')->where('name', 'theme_support_module')->update(array('value' => ''));
    }

    /**
     * 向 parameter 表插入一行（按允许列白名单过滤）。
     *
     * Theme 名义主表为 config，本方法写的是 parameter 表（跨表），不适用 create()；
     * 与类内其它方法一致直用 DB::table('parameter')，仅按白名单显式过滤键，不再借模型实例 fill。
     *
     * @param array $row name/lang/value/cue/group
     * @return void
     */
    public static function insertParameterRow(array $row)
    {
        $allowed = array('name', 'lang', 'value', 'cue', 'group');
        $data = array_intersect_key($row, array_flip($allowed));
        DB::table('parameter')->insert($data);
    }

    /**
     * @param string $name
     * @return bool
     */
    public static function parameterExistsByName($name)
    {
        return (bool) DB::table('parameter')->where('name', $name)->find();
    }
}
