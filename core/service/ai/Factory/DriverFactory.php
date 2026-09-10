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

namespace Dou\Core\Service\Ai\Factory;

use Dou\Core\Service\Ai\Driver\BaiduDriver;
use Dou\Core\Service\Ai\Driver\BailianDriver;
use Dou\Core\Service\Ai\Driver\OpenAiDriver;
use Dou\Core\Service\Ai\Driver\QwenDriver;
use Dou\Core\Service\Ai\Driver\VolcanoDriver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 供应商驱动简单工厂：provider.code → 驱动实例。
 *
 * 路由优先级：provider.code 注册表 > stream_format（qwen 兜底）> OpenAiDriver 默认。
 * 类 A/B 供应商无注册项，一律落到 OpenAiDriver，保持零配置兼容。
 */
class DriverFactory
{
    /** @var array provider.code → 驱动类名（内置类 C 供应商 + 可 register 扩展） */
    private static $codeMap = array(
        'bailian' => BailianDriver::class,
        'volcano' => VolcanoDriver::class,
        'baidu' => BaiduDriver::class,
    );

    /**
     * 按配置创建驱动实例。
     *
     * @param array $config resolveConfig 产物
     * @return \Dou\Core\Service\Ai\Driver\DriverInterface
     */
    public static function make(array $config)
    {
        $code = self::code($config);
        if ($code === 'qwen') {
            return new QwenDriver($config);
        }
        if (isset(self::$codeMap[$code])) {
            $class = self::$codeMap[$code];

            return new $class($config);
        }

        return new OpenAiDriver($config);
    }

    /**
     * 按配置解析驱动标识（与 make() 同一套路由规则）。
     *
     * 路由优先级：provider.code 注册表 > stream_format（qwen 兜底）> openai。
     * resolveConfig 的 config['driver'] 字段与 make() 路由均以此为准，避免两处维护。
     *
     * @param array $config 含 provider_code / stream_format 的配置（允许部分字段）
     * @return string 驱动标识（bailian/volcano/baidu/qwen/openai 或已注册的 provider.code）
     */
    public static function code(array $config)
    {
        $providerCode = isset($config['provider_code']) ? (string) $config['provider_code'] : '';
        if ($providerCode !== '' && isset(self::$codeMap[$providerCode])) {
            return $providerCode;
        }

        return (isset($config['stream_format']) && $config['stream_format'] === 'qwen') ? 'qwen' : 'openai';
    }

    /**
     * 注册 provider.code 对应的专属驱动（类 C 供应商接入时使用）。
     *
     * @param string $code provider.code
     * @param string $className 驱动类全名
     * @return void
     */
    public static function register($code, $className)
    {
        self::$codeMap[$code] = $className;
    }
}
