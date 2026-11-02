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
 * 惰性解析：仅首次调用 plugin() 时才构造实例，未调用则永不加载模块类，
 * 使不涉及插件功能的页面（含后台全站）不受模块升级状态影响。
 * 判断只做文件存在性（is_file），不触发 autoload；不满足即返回
 * {@see NullPluginService}，保证业务调用面始终非空。
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

            // 同一请求只解析一次；ProviderRegistry 重注册会换新闭包，缓存随之失效。
            static $service = null;
            if ($service !== null) {
                return $service;
            }

            if (self::pluginModuleReady()) {
                return $service = new \Dou\Core\Service\Plugin\PluginService();
            }

            return $service = new NullPluginService();
        });
    }

    /**
     * 插件真实现是否就绪：实现文件仍在磁盘（plugin 模块未被卸载）。
     *
     * 只用 is_file：class_exists 会触发 autoload 并真正声明模块类，
     * 模块“已安装但未升级”时是声明期致命错误（E_COMPILE_ERROR），无法捕获。
     *
     * @return bool
     */
    private static function pluginModuleReady()
    {
        return is_file(CORE_PATH . 'service/plugin/PluginService.php');
    }
}
