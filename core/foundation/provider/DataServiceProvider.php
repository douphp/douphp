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
use Dou\Core\Service\Noop\NullDataService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 碎片化数据访问能力 Provider：将 DataServiceContract 绑定到对应实现。
 *
 * 判断：features.data 开启且真实现类仍在磁盘（data 模块未卸载）。
 * 不满足即返回 {@see NullDataService}，保证 data() 始终可解析。
 */
class DataServiceProvider
{
    /**
     * 注册容器工厂。允许重复调用，覆盖前一次的工厂闭包。
     *
     * @param Container $container
     * @return void
     */
    public static function register(Container $container)
    {
        $container->factory('Dou\\Core\\Contract\\DataServiceContract', function ($c) {
            unset($c);
            if (self::dataModuleReady()) {
                return new \Dou\Core\Service\Data\DataService();
            }
            return new NullDataService();
        });
    }

    /**
     * data 真实现是否就绪：features.data 开启且实现类仍在磁盘。
     *
     * @return bool
     */
    private static function dataModuleReady()
    {
        if (!Config::get('features.data', false)) {
            return false;
        }
        return class_exists('Dou\\Core\\Service\\Data\\DataService');
    }
}
