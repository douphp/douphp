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

namespace Dou\Core\Service\Metadata;

use Dou\Core\Foundation\Configuration\Config;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 支持扩展字段的内容模块注册表。
 *
 * 清单维护在 config/module.php 的 link_metadata 桶（模块可拆卸，故不写死在固定配置文件里），
 * 写入来源三处：
 *   - 全新安装：安装包自带的 config/module.php；
 *   - 模块安装：模块包 _action/manifest.php 的 flags.link_metadata 声明（true 才写入），
 *     由 InstallService::changeModulePhp 落盘；
 *   - 存量站点升级：upgrade/resources/migrations/MetadataLedger 按已安装模块回填。
 *
 * 系统内置模块（page）随系统发行、不可拆卸，恒定纳入清单且不写入账本桶，
 * 故只在读取侧注入。
 *
 * 本类只负责读取清单与生成后台显示名。
 */
class MetadataModules
{
    /**
     * 系统内置内容模块：恒定纳入清单，不写入模块账本（config/module.php 的 link_metadata）。
     *
     * @var array
     */
    private static $builtin = array('page');

    /** @var array|null 请求内模块清单缓存 */
    private static $modules = null;

    /**
     * @param string $module
     * @return bool
     */
    public static function exists($module)
    {
        return in_array((string) $module, self::modules(), true);
    }

    /**
     * 模块显示名（后台定义页模块选择器）。
     *
     * @return array module => label
     */
    public static function labels()
    {
        $labels = array();
        foreach (self::modules() as $module) {
            $labels[$module] = lang($module, $module);
        }

        return $labels;
    }

    /**
     * @return array
     */
    private static function modules()
    {
        if (self::$modules !== null) {
            return self::$modules;
        }

        $modules = self::$builtin;
        foreach ((array) Config::get('module.link_metadata', array()) as $module) {
            if (is_string($module) && $module !== '' && !in_array($module, $modules, true)) {
                $modules[] = $module;
            }
        }

        self::$modules = $modules;

        return self::$modules;
    }
}
