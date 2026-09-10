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

namespace Dou\Core\Foundation\Provider;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Container\Container;
use Dou\Core\Service\Noop\NullLanguageService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 语言能力 Provider：将 LanguageContract / AdminLanguageContract 绑定到对应实现。
 *
 * 主判断：features.language；兜底：实现类是否仍在磁盘（卸载/未升级场景）。
 * 两条条件之一不满足即返回 {@see NullLanguageService}，保证业务调用面始终非空。
 */
class LanguageServiceProvider
{
    /**
     * 注册容器工厂。允许重复调用，覆盖前一次的工厂闭包。
     *
     * @param Container $container
     * @return void
     */
    public static function register(Container $container)
    {
        $container->factory('Dou\\Core\\Contract\\LanguageContract', function ($c) {
            unset($c);
            if (self::languageModuleReady()) {
                return new \Dou\Core\Service\Language\LanguageService();
            }
            return new NullLanguageService();
        });

        $container->factory('Dou\\Admin\\Contract\\AdminLanguageContract', function ($c) {
            unset($c);
            if (self::adminLanguageModuleReady()) {
                return new \Dou\Admin\Service\Language\LanguageAdminService();
            }
            return new NullLanguageService();
        });
    }

    /**
     * core 语言实现是否就绪：features.language 开启 + 模块类仍在磁盘。
     *
     * @return bool
     */
    private static function languageModuleReady()
    {
        if (empty(Config::get('features.language', false))) {
            return false;
        }
        return class_exists('Dou\\Core\\Service\\Language\\LanguageService');
    }

    /**
     * 后台语言实现是否就绪：features.language 开启 + admin 模块类仍在磁盘。
     *
     * @return bool
     */
    private static function adminLanguageModuleReady()
    {
        if (empty(Config::get('features.language', false))) {
            return false;
        }
        return class_exists('Dou\\Admin\\Service\\Language\\LanguageAdminService');
    }
}
