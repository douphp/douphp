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

namespace Dou\Core\Infra\Log;

use Dou\Core\Facade\Session;
use Dou\Core\Support\Num;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 日志记录类
 *
 * 提供分级日志记录功能。
 * 兼容 PHP 5.6+。
 */
class Log
{
    /**
     * 日志级别常量
     */
    const EMERGENCY = 'emergency';
    const ALERT = 'alert';
    const CRITICAL = 'critical';
    const ERROR = 'error';
    const WARNING = 'warning';
    const NOTICE = 'notice';
    const INFO = 'info';
    const DEBUG = 'debug';

    /**
     * 日志存储路径
     *
     * @var string
     */
    protected static $logPath;

    /**
     * 是否启用日志
     *
     * @var bool
     */
    protected static $enabled = true;

    /**
     * 自动补全请求上下文
     *
     * @var bool
     */
    protected static $autoContextEnabled = true;

    /**
     * 最低记录级别
     *
     * @var string
     */
    protected static $minLevel = self::DEBUG;

    /**
     * 级别权重
     *
     * @var array
     */
    protected static $levelWeights = [
        self::EMERGENCY => 8,
        self::ALERT => 7,
        self::CRITICAL => 6,
        self::ERROR => 5,
        self::WARNING => 4,
        self::NOTICE => 3,
        self::INFO => 2,
        self::DEBUG => 1,
    ];

    /**
     * 启用级别白名单（空数组表示不限制）
     *
     * @var array
     */
    protected static $enabledLevels = array();

    /**
     * 启用场景白名单（空数组表示不限制）
     *
     * @var array
     */
    protected static $enabledChannels = array();

    /**
     * 请求级 request_id（单次请求内复用）
     *
     * @var string
     */
    protected static $requestId = '';

    /**
     * 同类日志采样率（0~1，1 表示不过滤）
     *
     * @var float
     */
    protected static $sampleRate = 1.0;

    /**
     * 每分钟同 key 最大日志条数（0 表示不限制）
     *
     * @var int
     */
    protected static $maxPerMinutePerKey = 0;

    /**
     * 限流计数桶（内存级，请求周期内有效）
     *
     * @var array
     */
    protected static $rateBuckets = array();

    /**
     * 初始化日志路径
     */
    protected static function initPath()
    {
        if (static::$logPath === null) {
            static::$logPath = STORAGE_PATH . 'log/';
        }
    }

    /**
     * 设置日志存储路径
     *
     * @param string $path
     * @return void
     */
    public static function setPath($path)
    {
        static::$logPath = rtrim($path, '/\\') . '/';
    }

    /**
     * 启用或禁用日志
     *
     * @param bool $enabled
     * @return void
     */
    public static function setEnabled($enabled)
    {
        static::$enabled = (bool) $enabled;
    }

    /**
     * 设置自动上下文补全开关
     *
     * @param bool $enabled
     * @return void
     */
    public static function setAutoContextEnabled($enabled)
    {
        static::$autoContextEnabled = (bool) $enabled;
    }

    /**
     * 设置最低记录级别
     *
     * @param string $level
     * @return void
     */
    public static function setMinLevel($level)
    {
        if (isset(static::$levelWeights[$level])) {
            static::$minLevel = $level;
        }
    }

    /**
     * 设置允许写入的日志级别白名单（空数组表示不限制）
     *
     * @param array $levels
     * @return void
     */
    public static function setEnabledLevels(array $levels)
    {
        $normalized = array();
        foreach ($levels as $level) {
            $level = strtolower(trim((string) $level));
            if (isset(static::$levelWeights[$level])) {
                $normalized[$level] = 1;
            }
        }
        static::$enabledLevels = array_keys($normalized);
    }

    /**
     * 设置允许写入的日志场景白名单（空数组表示不限制）
     *
     * @param array $channels
     * @return void
     */
    public static function setEnabledChannels(array $channels)
    {
        $normalized = array();
        foreach ($channels as $channel) {
            $channel = strtolower(trim((string) $channel));
            if ($channel !== '') {
                $normalized[$channel] = 1;
            }
        }
        static::$enabledChannels = array_keys($normalized);
    }

    /**
     * 设置采样率（0~1）
     *
     * @param float|int|string $rate
     * @return void
     */
    public static function setSampleRate($rate)
    {
        $rate = (float) $rate;
        if ($rate < 0) {
            $rate = 0;
        }
        if ($rate > 1) {
            $rate = 1;
        }
        static::$sampleRate = $rate;
    }

    /**
     * 设置每分钟单 key 最大日志条数（0 表示不限制）
     *
     * @param int $max
     * @return void
     */
    public static function setMaxPerMinutePerKey($max)
    {
        $max = (int) $max;
        static::$maxPerMinutePerKey = $max > 0 ? $max : 0;
    }

    /**
     * 写入日志
     *
     * @param string $level 日志级别
     * @param string $message 日志内容
     * @param array $context 上下文数据
     * @return void
     */
    public static function write($level, $message, array $context = [])
    {
        $level = strtolower((string) $level);

        if (!static::$enabled) {
            return;
        }

        if (!isset(static::$levelWeights[$level])) {
            return;
        }

        if (static::$levelWeights[$level] < static::$levelWeights[static::$minLevel]) {
            return;
        }

        if (!empty(static::$enabledLevels) && !in_array($level, static::$enabledLevels, true)) {
            return;
        }

        $channel = static::resolveChannel($context);
        if (!empty(static::$enabledChannels) && !in_array($channel, static::$enabledChannels, true)) {
            return;
        }

        if (!isset($context['channel']) || $context['channel'] === '') {
            $context['channel'] = $channel;
        }

        if (static::$autoContextEnabled) {
            $context = static::appendAutoContext($context);
        }

        $context = static::normalizeContextForOutput($context);

        if (static::shouldSkipBySampleRate()) {
            return;
        }

        if (static::shouldSkipByRateLimit($level, (string) $message, $context)) {
            return;
        }

        static::initPath();

        if (!is_dir(static::$logPath)) {
            mkdir(static::$logPath, 0777, true);
        }

        $message = static::formatMessage($level, $message, $context);
        $file = static::$logPath . 'log_' . date('Y-m-d') . '.log';

        file_put_contents($file, $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * 是否命中采样丢弃
     *
     * @return bool
     */
    protected static function shouldSkipBySampleRate()
    {
        if (static::$sampleRate >= 1) {
            return false;
        }
        if (static::$sampleRate <= 0) {
            return true;
        }
        $rand = mt_rand(1, 10000) / 10000;
        return $rand > static::$sampleRate;
    }

    /**
     * 是否命中限流丢弃
     *
     * @param string $level
     * @param string $message
     * @param array $context
     * @return bool
     */
    protected static function shouldSkipByRateLimit($level, $message, array $context)
    {
        if (static::$maxPerMinutePerKey <= 0) {
            return false;
        }

        $minute = date('YmdHi');
        $channel = isset($context['channel']) ? (string) $context['channel'] : 'system';
        $key = $minute . '|' . $channel . '|' . $level . '|' . substr(md5($message), 0, 16);

        if (!isset(static::$rateBuckets[$key])) {
            static::$rateBuckets[$key] = 0;
        }
        static::$rateBuckets[$key]++;

        return static::$rateBuckets[$key] > static::$maxPerMinutePerKey;
    }

    /**
     * 输出前规整上下文（生产环境收敛 trace）
     *
     * @param array $context
     * @return array
     */
    protected static function normalizeContextForOutput(array $context)
    {
        $isDebug = static::$minLevel === self::DEBUG;
        if (!$isDebug && isset($context['trace']) && is_string($context['trace'])) {
            $context['trace'] = substr($context['trace'], 0, 1200);
        }
        return $context;
    }

    /**
     * 解析日志场景
     *
     * @param array $context
     * @return string
     */
    protected static function resolveChannel(array $context)
    {
        if (isset($context['channel'])) {
            $channel = strtolower(trim((string) $context['channel']));
            if ($channel !== '') {
                return $channel;
            }
        }
        return 'system';
    }

    /**
     * 自动补全通用上下文
     *
     * @param array $context
     * @return array
     */
    protected static function appendAutoContext(array $context)
    {
        if (!isset($context['request_id']) || $context['request_id'] === '') {
            $context['request_id'] = static::getRequestId();
        }

        if (!isset($context['scene']) || $context['scene'] === '') {
            $context['scene'] = static::detectScene();
        }

        if (!isset($context['ip']) || $context['ip'] === '') {
            $context['ip'] = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        }

        if (!isset($context['route']) || $context['route'] === '') {
            $context['route'] = trim(static::currentRouteString());
        }

        if (!isset($context['request_uri']) || $context['request_uri'] === '') {
            $context['request_uri'] = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        }

        if (!isset($context['request_method']) || $context['request_method'] === '') {
            $context['request_method'] = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '';
        }

        if (!isset($context['module']) || $context['module'] === '') {
            $context['module'] = static::deriveRoutePart(0);
        }

        if (!isset($context['action']) || $context['action'] === '') {
            $context['action'] = static::deriveRoutePart(1);
        }

        if (!isset($context['user_id']) || !$context['user_id']) {
            $context['user_id'] = static::detectUserId();
        }

        if (!isset($context['admin_id']) || !$context['admin_id']) {
            $context['admin_id'] = static::detectAdminId();
        }

        if (!isset($context['work_id']) || !$context['work_id']) {
            $context['work_id'] = static::detectWorkId();
        }

        return $context;
    }

    /**
     * 获取当前请求 request_id
     *
     * @return string
     */
    protected static function getRequestId()
    {
        if (static::$requestId !== '') {
            return static::$requestId;
        }

        static::$requestId = substr(md5(uniqid('', true) . mt_rand()), 0, 16);
        return static::$requestId;
    }

    /**
     * 推断当前运行场景
     *
     * @return string
     */
    protected static function detectScene()
    {
        if (defined('IS_ADMIN') && IS_ADMIN) {
            return 'admin';
        }
        if (defined('IS_API') && IS_API) {
            return 'api';
        }
        return 'front';
    }

    /**
     * 从 route 中提取分段
     *
     * @param int $idx
     * @return string
     */
    protected static function deriveRoutePart($idx)
    {
        $route = trim(static::currentRouteString(), '/');
        if ($route === '') {
            return '';
        }

        $parts = explode('/', $route);
        return isset($parts[$idx]) ? (string) $parts[$idx] : '';
    }

    /**
     * 当前请求路由字符串（已剥语言前缀）。
     *
     * route 字符串自入口起只经 Request 流转；此处经容器安全读取，
     * 在 Request 尚未绑定（CLI / bootstrap 早期错误）时返回空串。
     *
     * @return string
     */
    protected static function currentRouteString()
    {
        $container = \Dou\Core\Foundation\Container\Container::getInstance();
        if (!$container->bound(\Dou\Core\Web\Http\Request::class)) {
            return '';
        }

        return (string) $container->make(\Dou\Core\Web\Http\Request::class)->routeString();
    }

    /**
     * 探测当前会员 ID
     *
     * @return int
     */
    protected static function detectUserId()
    {
        return (int) Session::get('user_id', 0);
    }

    /**
     * 探测当前管理员 ID
     *
     * @return int
     */
    protected static function detectAdminId()
    {
        return (int) Session::get('admin_id', 0);
    }

    /**
     * 探测当前 work 身份 ID（来源 _SESSION[DOU_ID]['work_id']，由 Auth 门面 hydrate 时同步写入）。
     *
     * @return int
     */
    protected static function detectWorkId()
    {
        return (int) Session::get('work_id', 0);
    }

    /**
     * 格式化日志消息
     *
     * @param string $level
     * @param string $message
     * @param array $context
     * @return string
     */
    protected static function formatMessage($level, $message, array $context)
    {
        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);
        $formatted = "[{$timestamp}] {$levelUpper}: {$message}";

        if (!empty($context)) {
            $formatted .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }

        return $formatted;
    }

    /**
     * Emergency 级别日志
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function emergency($message, array $context = [])
    {
        static::write(self::EMERGENCY, $message, $context);
    }

    /**
     * Alert 级别日志
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function alert($message, array $context = [])
    {
        static::write(self::ALERT, $message, $context);
    }

    /**
     * Critical 级别日志
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function critical($message, array $context = [])
    {
        static::write(self::CRITICAL, $message, $context);
    }

    /**
     * Error 级别日志
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function error($message, array $context = [])
    {
        static::write(self::ERROR, $message, $context);
    }

    /**
     * Warning 级别日志
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function warning($message, array $context = [])
    {
        static::write(self::WARNING, $message, $context);
    }

    /**
     * Notice 级别日志
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function notice($message, array $context = [])
    {
        static::write(self::NOTICE, $message, $context);
    }

    /**
     * Info 级别日志
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function info($message, array $context = [])
    {
        static::write(self::INFO, $message, $context);
    }

    /**
     * Debug 级别日志
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function debug($message, array $context = [])
    {
        static::write(self::DEBUG, $message, $context);
    }

    /**
     * 清空当前 logPath 下按日命名的日志文件（log_YYYY-MM-DD.log）
     *
     * @param int $keepDays 保留最近 N 天的日志，0 表示全部删除
     * @return void
     */
    public static function clean($keepDays = 30)
    {
        static::initPath();
        static::cleanDailyLogFilesInDir(static::$logPath, $keepDays);
    }

    /**
     * 清理 storage/log/front、admin、api 三端目录下的按日日志（不处理根目录）
     *
     * @param int $keepDays 保留最近 N 天的日志，0 表示全部删除
     * @return void
     */
    public static function cleanRuntimeDirs($keepDays)
    {
        $base = STORAGE_PATH . 'log/';
        foreach (array('front', 'admin', 'api') as $shell) {
            static::cleanDailyLogFilesInDir($base . $shell . '/', $keepDays);
        }
    }

    /**
     * 按站点配置节流执行自动清理（最多约每 24 小时一次）
     *
     * @param mixed $keepDaysRaw site.log_clean_keep_days：≤0 关闭；≥1 为保留最近 N 天（非数字经 {@see Num::toIntOrZero} 后 ≤0 视为关闭）
     * @param int $minInterval 两次清理最小间隔秒数
     * @return void
     */
    public static function tryAutoCleanFromConfig($keepDaysRaw, $minInterval = 86400)
    {
        $keepDays = Num::toIntOrZero($keepDaysRaw);
        if ($keepDays < 1) {
            return;
        }
        $marker = STORAGE_PATH . 'log/.last_auto_clean';
        $now = time();

        if (is_file($marker)) {
            $raw = @file_get_contents($marker);
            $last = Num::toIntOrZero($raw);
            if ($last > 0 && ($now - $last) < (int) $minInterval) {
                return;
            }
        }

        static::cleanRuntimeDirs($keepDays);

        $dir = dirname($marker);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($marker, (string) $now, LOCK_EX);
    }

    /**
     * 删除某目录下早于 keepDays 的 log_*.log（keepDays 为 0 时删除全部匹配文件）
     *
     * @param string $dir 须以 / 结尾或会被规范为以 / 结尾
     * @param int $keepDays
     * @return void
     */
    private static function cleanDailyLogFilesInDir($dir, $keepDays)
    {
        $dir = rtrim((string) $dir, '/\\') . '/';
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . 'log_*.log');
        if (!is_array($files)) {
            return;
        }

        $now = time();
        $keepDays = (int) $keepDays;

        foreach ($files as $file) {
            if ($keepDays > 0) {
                $fileTime = @filemtime($file);
                if ($fileTime !== false && ($now - $fileTime) < $keepDays * 86400) {
                    continue;
                }
            }
            @unlink($file);
        }
    }
}
