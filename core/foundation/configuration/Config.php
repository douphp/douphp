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

namespace Dou\Core\Foundation\Configuration;

use Dou\Core\Support\Arr;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 配置管理类
 *
 * 提供统一的配置读取和设置功能，支持点语法。
 * 兼容 PHP 5.6+。
 */
class Config
{
    /**
     * 配置数据存储
     *
     * @var array
     */
    protected static $items = [];

    /**
     * 批量加载配置
     *
     * @param array $config
     * @return void
     */
    public static function load(array $config)
    {
        static::$items = array_merge(static::$items, $config);
    }

    /**
     * 设置配置值（支持点语法）
     *
     * @param string|array $key
     * @param mixed $value
     * @return void
     */
    public static function set($key, $value = null)
    {
        if (is_array($key)) {
            static::$items = array_merge(static::$items, $key);
            return;
        }

        Arr::set(static::$items, $key, $value);
    }

    /**
     * 获取配置值（支持点语法）
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public static function get($key = null, $default = null)
    {
        if (is_null($key)) {
            return static::$items;
        }

        return Arr::get(static::$items, $key, $default);
    }

    /**
     * 判断配置项是否存在
     *
     * @param string $key
     * @return bool
     */
    public static function has($key)
    {
        return Arr::has(static::$items, $key);
    }

    /**
     * 删除配置项
     *
     * @param string $key
     * @return void
     */
    public static function forget($key)
    {
        Arr::forget(static::$items, $key);
    }

    /**
     * 获取所有配置
     *
     * @return array
     */
    public static function all()
    {
        return static::$items;
    }

    /**
     * 清空所有配置
     *
     * @return void
     */
    public static function clear()
    {
        static::$items = [];
    }
}
