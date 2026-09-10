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

use Dou\Core\Foundation\Container\Container;
use Dou\Core\Service\Noop\NullPluginService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 插件查询能力 Provider：将 PluginServiceContract 绑定到对应实现。
 *
 * 判断：真实现类是否仍在磁盘（plugin 模块未卸载）。
 * 不满足即返回 {@see NullPluginService}，保证业务调用面始终非空；
 * features.plugin 与 plugin 表存在性的细颗粒判断保留在真实现内部各方法的
 * isAvailable() 守卫里，与 LanguageServiceProvider "双闸门交给真实现内部"思路一致。
 */
class PluginServiceProvider
{
    /**
     * 注册容器工厂。允许重复调用，覆盖前一次的工厂闭包。
     *
     * @param Container $container
     * @return void
     */
    public static function register(Container $container)
    {
        $container->factory('Dou\\Core\\Contract\\PluginServiceContract', function ($c) {
            unset($c);
            if (self::pluginModuleReady()) {
                return new \Dou\Core\Service\Plugin\PluginService();
            }
            return new NullPluginService();
        });
    }

    /**
     * 插件真实现是否就绪：实现类仍在磁盘（plugin 模块未被卸载）。
     *
     * @return bool
     */
    private static function pluginModuleReady()
    {
        return class_exists('Dou\\Core\\Service\\Plugin\\PluginService');
    }
}
