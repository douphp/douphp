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

namespace Dou\Core\Web\Routing;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Support\Naming;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 模块分类单一来源
 *
 * 三端（front / admin / api）共用的模块属性查询入口：固定前台模块、栏目 / 单表模块、系统保留首段、
 * 父模块启用判定。所有判定从 Config（setting.*）与命名规范（{@see Naming}）派生；三端路由解析完全
 * 走声明式条目（{@see RouteManifest} 与各端 Matcher / Resolver）。
 */
class ModuleRegistry
{
    /**
     * 前台固定内建模块（不依赖 module.all_module 启用）
     *
     * @return array
     */
    public static function fixedFrontModules()
    {
        return (array) Config::get('system.front_fixed_module', array());
    }

    /**
     * 系统保留首段（不出现在 config/route.php 风格规则中的内置端点）
     *
     * @return array
     */
    public static function systemReservedFirstSegments()
    {
        return (array) Config::get('system.reserved_first_segment', array());
    }

    /**
     * 是否栏目型模块（按 base 判定，module.column_module）
     *
     * @param string $module
     * @return bool
     */
    public function isColumn($module)
    {
        $base = Naming::baseModule($module);
        return in_array($base, (array) Config::get('module.column_module'), true);
    }

    /**
     * 是否单表模块（按 base 判定，module.single_module）
     *
     * @param string $module
     * @return bool
     */
    public function isSingle($module)
    {
        $base = Naming::baseModule($module);
        return in_array($base, (array) Config::get('module.single_module'), true);
    }

    /**
     * 是否前台固定内建模块（按 base 判定）
     *
     * @param string $module
     * @return bool
     */
    public function isFixedFront($module)
    {
        $base = Naming::baseModule($module);
        return in_array($base, self::fixedFrontModules(), true);
    }

    /**
     * 是否系统保留首段
     *
     * @param string $segment
     * @return bool
     */
    public function isSystemReserved($segment)
    {
        return $segment !== '' && in_array($segment, self::systemReservedFirstSegments(), true);
    }

    /**
     * 父模块是否在 module.all_module 已启用列表中
     *
     * @param string $parent
     * @return bool
     */
    public function isParentModuleEnabled($parent)
    {
        return in_array($parent, (array) Config::get('module.all_module'), true);
    }
}
