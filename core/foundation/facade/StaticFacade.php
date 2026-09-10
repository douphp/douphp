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

namespace Dou\Core\Foundation\Facade;

use Dou\Core\Foundation\Container\Container;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 静态门面基类：以 __callStatic 代理调用到容器中的底层实例。
 *
 * 解析顺序（先后）：
 *   1) 类内 swap 槽（测试期手动注入的 mock/stub）
 *   2) 全局 Container 单例中以 getAccessor() 注册的实例
 *
 * 子类须实现 getAccessor() 返回容器 key（通常为底层实例类名）。
 *
 * 本基类不持有运行时状态，纯静态代理；子类不应自带业务逻辑，仅作语法糖。
 */
abstract class StaticFacade
{
    /**
     * 已解析实例的 swap 槽（accessor => instance）。
     *
     * @var array<string, object>
     */
    private static $resolvedInstances = array();

    /**
     * 子类返回容器 key（一般是底层实例类名）。
     *
     * @return string
     */
    protected static function getAccessor()
    {
        throw new \RuntimeException(get_called_class() . ' must implement getAccessor().');
    }

    /**
     * 解析底层实例：优先 swap 槽，其次容器单例。
     *
     * @return object
     * @throws \RuntimeException 容器未注册且 swap 槽为空
     */
    public static function getFacadeRoot()
    {
        $accessor = static::getAccessor();

        if (isset(self::$resolvedInstances[$accessor])) {
            return self::$resolvedInstances[$accessor];
        }

        $container = Container::getInstance();
        if (!$container->has($accessor)) {
            throw new \RuntimeException(
                'Facade accessor [' . $accessor . '] is not bound to the container. '
                . 'Make sure it is registered before any static call.'
            );
        }

        return $container->make($accessor);
    }

    /**
     * 测试期替换底层实例。
     *
     * @param object $instance
     * @return void
     */
    public static function swap($instance)
    {
        self::$resolvedInstances[static::getAccessor()] = $instance;
    }

    /**
     * 清理当前门面的 swap 槽（恢复从容器解析）。
     *
     * @return void
     */
    public static function clearResolvedInstance()
    {
        unset(self::$resolvedInstances[static::getAccessor()]);
    }

    /**
     * 清理全部 swap 槽（一般用于测试 tearDown）。
     *
     * @return void
     */
    public static function clearAllResolvedInstances()
    {
        self::$resolvedInstances = array();
    }

    /**
     * 静态代理：把对门面的静态调用转发到底层实例的同名实例方法。
     *
     * @param string $method
     * @param array $args
     * @return mixed
     */
    public static function __callStatic($method, $args)
    {
        $instance = static::getFacadeRoot();
        return call_user_func_array(array($instance, $method), $args);
    }
}
