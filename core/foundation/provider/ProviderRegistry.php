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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 轻量 Provider 注册总线。
 *
 * 三端 Init 在 Config::set('features', ...) 就绪后调用 {@see registerAll()}
 * 一次性注册所有平台能力 Provider，每个 Provider 暴露静态 register(Container)。
 * 不引入 boot 阶段，调用语义保持简单。
 */
class ProviderRegistry
{
    /**
     * 已挂入的平台能力 Provider 列表。
     *
     * @var string[]
     */
    private static $providers = array(
        'Dou\\Core\\Foundation\\Provider\\LanguageServiceProvider',
        'Dou\\Core\\Foundation\\Provider\\PluginServiceProvider',
        'Dou\\Core\\Foundation\\Provider\\DataServiceProvider',
    );

    /**
     * 依次调用各 Provider::register($container)。
     *
     * @param Container $container
     * @return void
     */
    public static function registerAll(Container $container)
    {
        foreach (self::$providers as $providerClass) {
            if (!class_exists($providerClass)) {
                continue;
            }
            call_user_func(array($providerClass, 'register'), $container);
        }
    }
}
