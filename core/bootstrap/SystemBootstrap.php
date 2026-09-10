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

namespace Dou\Core\Bootstrap;

use Dou\Core\Foundation\Container\Container;
use Dou\Core\Service\System\CoreModuleSettings;
use Dou\Core\Service\System\ModuleFeatureGate;
use Dou\Core\Service\System\ModuleLanguageManifest;
use Dou\Core\Service\System\ModuleSettingReader;
use Dou\Core\Service\System\SystemConstantsReader;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 系统设定启动装配器。
 *
 * 为三端 Init 编排 {@see ModuleSettingReader} / {@see CoreModuleSettings} /
 * {@see ModuleLanguageManifest} / {@see ModuleFeatureGate} / {@see SystemConstantsReader}，
 * 返回结构化结果数组，含四个并列分区：
 *  - `module`   模块账本（`column_module` / `single_module` / `link_*` / `no_show_*` + 派生 `all_module`）
 *  - `system`   框架恒定常量 + `admin_rewrite`
 *  - `features` 功能开关
 *  - `lang`     当前请求待加载的语言文件清单
 *
 * 本类不写 Config，与 {@see SiteBootstrap} 对应 {@see \Dou\Core\Service\Config\SiteConfigAssembler}
 * 的分工一致；写入 Config('module') / Config('system') / Config('features') 与暂存 lang 清单的
 * 责任留在调用方 Init。
 */
class SystemBootstrap
{
    /**
     * 装配核心设定。
     *
     * @param array $options 装配选项：
     *                       - bool   isAdmin          是否后台调用（调用方按场景显式传入）
     *                       - string langPack         当前语言包路径（如 zh_cn、zh_cn/admin、en_us）
     *                       - bool   includeAdminSort 是否在 features 中纳入 sort 开关（仅 admin 端）
     *                       - bool   adminSort        sort 当前值（由调用方 Session 等来源决定）
     * @return array { module: array, system: array, features: array, lang: array }
     */
    public static function loadCore(array $options)
    {
        $langPack = isset($options['langPack']) ? (string) $options['langPack'] : '';
        $includeAdminSort = !empty($options['includeAdminSort']);
        $adminSort = !empty($options['adminSort']);

        $container = Container::getInstance();

        /** @var ModuleSettingReader $reader */
        $reader = $container->make(ModuleSettingReader::class);
        $raw = $reader->read();

        /** @var CoreModuleSettings $modules */
        $modules = $container->make(CoreModuleSettings::class);
        $module = $modules->build($raw);
        $allModules = isset($module['all_module']) ? (array) $module['all_module'] : array();

        /** @var SystemConstantsReader $constants */
        $constants = $container->make(SystemConstantsReader::class);
        $system = $constants->read();
        $system['admin_rewrite'] = defined('ADMIN_REWRITE') && ADMIN_REWRITE;

        /** @var ModuleLanguageManifest $langManifest */
        $langManifest = $container->make(ModuleLanguageManifest::class);
        $lang = $langManifest->build($allModules, $langPack);

        /** @var ModuleFeatureGate $gate */
        $gate = $container->make(ModuleFeatureGate::class);
        $features = $gate->resolve($allModules, $includeAdminSort, $adminSort);

        return array(
            'module' => $module,
            'system' => $system,
            'features' => $features,
            'lang' => $lang,
        );
    }
}
