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
 * 碎片化数据访问能力 Provider：将 DataServiceContract 绑定到对应实现。
 *
 * data 已是系统核心模块（不可卸载），无条件绑定真实现 {@see \Dou\Core\Service\Data\DataService}。
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
            return new \Dou\Core\Service\Data\DataService();
        });
    }
}
