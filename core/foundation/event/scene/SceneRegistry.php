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

namespace Dou\Core\Foundation\Event\Scene;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 场景注册表（按场景分发处理器）。
 *
 * 设计要点：
 * - 纯静态注册/分发器，与 {@see \Dou\Core\Foundation\Event\Event} 同层定位。
 * - 业务侧处理器映射由实现 `public static function register()`
 *   的注册器类（bootstrapper）在 {@see dispatch()} 首次触发时贡献。
 * - 注册器通过 {@see addBootstrapper()} 在 bootstrap 早期登记类名，
 *   实际执行延后到 Config 等运行时状态就绪后的首次分发。
 */
class SceneRegistry
{
    /**
     * 场景 => 路由键 => callable(): SceneHandler。
     *
     * @var array<string,array<string,callable>>
     */
    private static $factories = array();

    /**
     * 待执行的注册器类名集合（需提供 public static function register()）。
     *
     * @var array<int,string>
     */
    private static $bootstrappers = array();

    /** @var bool 是否已完成一次注册器引导 */
    private static $booted = false;

    /**
     * 注册一个场景处理器工厂。
     *
     * @param string $scene
     * @param string $key 路由键（unicast 命中后只触发该 key 对应处理器）
     * @param callable $factory function(): SceneHandler
     * @return void
     */
    public static function register($scene, $key, $factory)
    {
        if (!isset(self::$factories[$scene])) {
            self::$factories[$scene] = array();
        }
        self::$factories[$scene][$key] = $factory;
    }

    /**
     * 追加一个注册器类（仅登记类名，延迟到首次 dispatch 时执行 register()）。
     *
     * 多次追加同一类名将被多次执行；注册器自身应保证幂等。
     *
     * @param string $bootstrapperClass
     * @return void
     */
    public static function addBootstrapper($bootstrapperClass)
    {
        self::$bootstrappers[] = (string) $bootstrapperClass;
        self::$booted = false;
    }

    /**
     * 分发一个场景。
     *
     * 当 $key 非空且该 key 已有对应处理器时仅触发该处理器；
     * 否则扇出到该场景下所有处理器。
     *
     * @param string $scene
     * @param array $payload
     * @param string $key 可选路由键
     * @return void
     */
    public static function dispatch($scene, array $payload, $key = '')
    {
        self::ensureBooted();

        if (empty(self::$factories[$scene]) || !is_array(self::$factories[$scene])) {
            return;
        }

        $key = (string) $key;
        if ($key !== '' && isset(self::$factories[$scene][$key])) {
            self::invokeHandler(self::$factories[$scene][$key], $scene, $payload);
            return;
        }

        foreach (self::$factories[$scene] as $factory) {
            self::invokeHandler($factory, $scene, $payload);
        }
    }

    /**
     * 重置内部状态（仅供测试/重启场景使用）。
     *
     * @return void
     */
    public static function reset()
    {
        self::$factories = array();
        self::$bootstrappers = array();
        self::$booted = false;
    }

    /**
     * 调用单个处理器工厂并触发 handle。
     *
     * @param callable $factory
     * @param string $scene
     * @param array $payload
     * @return void
     */
    private static function invokeHandler($factory, $scene, array $payload)
    {
        $handler = call_user_func($factory);
        if ($handler instanceof SceneHandler) {
            $handler->handle($scene, $payload);
        }
    }

    /**
     * 首次 dispatch 前完成一次注册器引导。
     *
     * @return void
     */
    private static function ensureBooted()
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        foreach (self::$bootstrappers as $bootstrapperClass) {
            if ($bootstrapperClass === '' || !class_exists($bootstrapperClass)) {
                continue;
            }
            if (!method_exists($bootstrapperClass, 'register')) {
                continue;
            }
            call_user_func(array($bootstrapperClass, 'register'));
        }
    }
}
