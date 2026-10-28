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

namespace Dou\Core\Service\Data;

use Dou\Core\Foundation\Configuration\Config;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * data 分组 → 所属「关联模板」标识（dou_data.theme 列）的解析器。
 *
 * 约定：
 * - 常规分组（common / banner / index / 业务模块分组…）归属当前运行的网站主题（site.site_theme）；
 * - 小程序分组（miniprogram）归属当前启用的小程序模板包（site.miniprogram_code，如 default / shop），
 *   因为小程序模板与网站主题是两套独立的模板包，其碎片化内容也应各存一套；api 端可由请求声明的
 *   包身份覆盖该值（见 {@see DataTemplate::useCode()}），使多个小程序包各读各的数据。
 *
 * 同一 code 在不同模板下是 dou_data 里两条独立记录，name / text / image / link 各自独立；
 * 多语言名称按行主键存储，同样互不影响。
 */
class DataTemplate
{
    /**
     * 小程序数据分组标识。
     */
    const MINIPROGRAM_GROUP = 'miniprogram';

    /**
     * 请求级模板标识覆盖（仅 api 端按包身份设置；null 表示未声明、走后台启用值）。
     *
     * @var string|null
     */
    private static $requestCode = null;

    /**
     * 声明当前请求使用的小程序模板标识。
     *
     * 由 api 入口按 X-MP-Code 头校验（目录存在、字符合法）后写入；
     * 只影响本次请求，不写回任何配置。
     *
     * @param string $code 模板标识（miniprogram/ 下的目录名）
     * @return void
     */
    public static function useCode($code)
    {
        self::$requestCode = (string) $code;
    }

    /**
     * 清除请求级覆盖，回到后台启用值（长驻进程 / 测试场景复位用）。
     *
     * @return void
     */
    public static function clearCode()
    {
        self::$requestCode = null;
    }

    /**
     * 当前请求使用的小程序模板标识：请求声明优先，其次后台启用值。
     *
     * @return string
     */
    public static function currentCode()
    {
        if (self::$requestCode !== null) {
            return self::$requestCode;
        }

        return (string) Config::get('site.miniprogram_code', '');
    }

    /**
     * 解析某 data 分组所属的模板标识。
     *
     * 小程序分组取当前请求声明的包标识（{@see DataTemplate::currentCode()}，未声明时为
     * site.miniprogram_code），为空时回落 site.site_theme，避免写入空 theme 导致记录在任何
     * 分组列表中都查不到；其余分组直接取 site.site_theme。
     *
     * @param string $dataGroup data_group
     * @return string 模板标识（theme 列取值）
     */
    public static function codeForGroup($dataGroup)
    {
        if ((string) $dataGroup === self::MINIPROGRAM_GROUP) {
            $code = self::currentCode();
            if ($code !== '') {
                return $code;
            }
        }

        return (string) Config::get('site.site_theme', '');
    }

    /**
     * 判断一条 data 记录是否属于小程序端。
     *
     * 只按分组判定：data_group 为 miniprogram 即属于小程序端。
     * 不按 theme 判定：theme 只决定归属哪个小程序模板，且当 site_theme 与 miniprogram_code
     * 同名（如两边都叫 default）时 theme 无法区分端，会把 PC 记录误判成小程序端。
     *
     * @param string $dataGroup data_group
     * @return bool
     */
    public static function isMiniprogramRecord($dataGroup)
    {
        return (string) $dataGroup === self::MINIPROGRAM_GROUP;
    }

    /**
     * 当前请求是否运行在小程序端（api 入口）。
     *
     * 只有 api/init/Init.php 会定义 IS_MINIPROGRAM，前台 / 后台均为 false。
     *
     * @return bool
     */
    public static function inMiniprogram()
    {
        return defined('IS_MINIPROGRAM') && IS_MINIPROGRAM;
    }
}
