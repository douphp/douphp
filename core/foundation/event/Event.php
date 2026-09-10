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

namespace Dou\Core\Foundation\Event;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 事件系统
 *
 * 提供简单的事件注册和触发机制。
 * 兼容 PHP 5.6+。
 */
class Event
{
    /**
     * 监听器列表
     *
     * @var array
     */
    protected static $listeners = [];

    /**
     * 注册事件监听器
     *
     * @param string $event 事件名称
     * @param callable $listener 监听器回调
     * @param int $priority 优先级（数字越大越早执行）
     * @return void
     */
    public static function listen($event, callable $listener, $priority = 0)
    {
        if (!isset(static::$listeners[$event])) {
            static::$listeners[$event] = [];
        }

        static::$listeners[$event][] = [
            'callback' => $listener,
            'priority' => (int) $priority,
        ];

        // 按优先级排序
        usort(static::$listeners[$event], function ($a, $b) {
            return $b['priority'] - $a['priority'];
        });
    }

    /**
     * 触发事件
     *
     * @param string $event 事件名称
     * @param mixed ...$params 传递给监听器的参数
     * @return array 返回每个监听器的执行结果
     */
    public static function fire($event, ...$params)
    {
        $results = [];

        if (!isset(static::$listeners[$event])) {
            return $results;
        }

        foreach (static::$listeners[$event] as $listener) {
            $results[] = call_user_func_array($listener['callback'], $params);
        }

        return $results;
    }

    /**
     * 触发事件（别名）
     *
     * @param string $event
     * @param mixed ...$params
     * @return array
     */
    public static function dispatch($event, ...$params)
    {
        return static::fire($event, ...$params);
    }

    /**
     * 检查事件是否有监听器
     *
     * @param string $event
     * @return bool
     */
    public static function hasListeners($event)
    {
        return !empty(static::$listeners[$event]);
    }

    /**
     * 获取事件的所有监听器
     *
     * @param string $event
     * @return array
     */
    public static function getListeners($event)
    {
        return isset(static::$listeners[$event]) ? static::$listeners[$event] : [];
    }

    /**
     * 移除事件的所有监听器
     *
     * @param string $event
     * @return void
     */
    public static function forget($event)
    {
        unset(static::$listeners[$event]);
    }

    /**
     * 移除所有监听器
     *
     * @return void
     */
    public static function clear()
    {
        static::$listeners = [];
    }
}
