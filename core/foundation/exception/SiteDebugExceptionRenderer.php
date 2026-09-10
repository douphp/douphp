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

namespace Dou\Core\Foundation\Exception;

use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Vendor\Whoops\Whoops;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 当站点开启调试时，向开发者输出未捕获的通用 Exception 详情。
 *
 * 开启方式（按优先级）：
 * 1. {@see data/config.php} 中 define('DOU_DEBUG', true)（应急，优先于数据库，无需登录后台）
 * 2. 配置表 dou_config 的 name=debug（{@see Config::get}('site.debug')），或后台「开发者」修改同一项
 */
class SiteDebugExceptionRenderer
{
    /**
     * 是否开启站点调试（与 InitTrait::initLogRuntime 中日志级别判断一致）。
     *
     * @return bool
     */
    public static function isSiteDebugEnabled()
    {
        if (defined('DOU_DEBUG') && self::parseDebugFlag(constant('DOU_DEBUG'))) {
            return true;
        }

        return self::parseDebugFlag(Config::get('site.debug', false));
    }

    /**
     * 解析 debug 开关原始值（库内 debug、DOU_DEBUG 共用）。
     *
     * @param mixed $raw
     * @return bool
     */
    private static function parseDebugFlag($raw)
    {
        if ($raw === true || $raw === 1) {
            return true;
        }
        if ($raw === false || $raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return false;
        }
        if (is_string($raw)) {
            $v = strtolower(trim($raw));

            return in_array($v, array('1', 'true', 'on', 'yes'), true);
        }

        return (bool) $raw;
    }

    /**
     * 当前请求是否应按 JSON 调试响应（API 端或 Ajax / Accept: application/json）。
     *
     * 与 {@see front_render_uncaught}、{@see api_render_uncaught} 判定一致，
     * 用于避免 Whoops 全局 HTML handler 接管 JSON 请求。
     *
     * @return bool
     */
    public static function isJsonLikeRequest()
    {
        if (defined('IS_API') && constant('IS_API')) {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return true;
        }
        $accept = isset($_SERVER['HTTP_ACCEPT']) ? strtolower((string) $_SERVER['HTTP_ACCEPT']) : '';
        if ($accept !== '' && strpos($accept, 'application/json') !== false) {
            return true;
        }

        return false;
    }

    /**
     * site.debug 开启时是否应注册 Whoops 全局 HTML 兜底（仅 front/admin 常规 HTML 请求）。
     *
     * @return bool
     */
    public static function shouldRegisterWhoopsGlobal()
    {
        return !self::isJsonLikeRequest();
    }

    /**
     * 解析调试输出渠道标识（front / admin / api / app）。
     *
     * @return string
     */
    public static function resolveDebugChannel()
    {
        if (defined('IS_ADMIN') && constant('IS_ADMIN')) {
            return 'admin';
        }
        if (defined('IS_API') && constant('IS_API')) {
            return 'api';
        }

        return 'front';
    }

    /**
     * $e 是否为可输出的异常对象（PHP 5.6 Exception，7+ Throwable）。
     *
     * @param mixed $e
     * @return bool
     */
    public static function isThrowableLike($e)
    {
        if (!is_object($e)) {
            return false;
        }
        if ($e instanceof \Exception) {
            return true;
        }

        return interface_exists('Throwable', false) && $e instanceof \Throwable;
    }

    /**
     * 输出 HTML 调试页并结束请求（HTTP 500）。
     *
     * @param \Throwable $e 兼容 PHP 7+ 的 Error 与 Exception
     * @param string $channel 标识来源，如 front / admin
     * @return void
     */
    public static function renderAndExitForHtml($e, $channel = '')
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: text/html; charset=utf-8');
        }

        // 优先走 Whoops PrettyPage；不可用或自身抛错时降级到下方 plain HTML。
        if (self::isThrowableLike($e) && class_exists('Dou\\Vendor\\Whoops\\Whoops')) {
            try {
                Whoops::render($e, $channel !== '' ? $channel : 'app');
            } catch (\Exception $we) {
                // 任何降级路径都不重抛，继续走兜底 plain HTML（PHP 5.6 仅 catch Exception）。
            }
        }

        if (!self::isThrowableLike($e)) {
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Uncaught exception</title></head><body>';
            echo '<h1>Uncaught exception</h1><p>Invalid exception value.</p></body></html>';
            exit;
        }

        $label = $channel !== '' ? htmlspecialchars($channel, ENT_QUOTES, 'UTF-8') : 'app';
        $class = htmlspecialchars(get_class($e), ENT_QUOTES, 'UTF-8');
        $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
        $line = (int) $e->getLine();
        $trace = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Uncaught exception</title></head><body>';
        echo '<h1>Uncaught exception (' . $label . ')</h1>';
        echo '<p><strong>' . $class . '</strong>: ' . $msg . '</p>';
        echo '<p>' . $file . ':' . $line . '</p>';
        echo '<pre>' . $trace . '</pre>';
        echo '</body></html>';
        exit;
    }

    /**
     * 构造 API JSON 的 errors 载荷（含类名、文件、行号、堆栈片段）。
     *
     * @param \Throwable $e 兼容 PHP 7+ 的 Error 与 Exception
     * @return array
     */
    public static function apiDebugErrors($e)
    {
        if (!self::isThrowableLike($e)) {
            return array(
                'exception' => 'unknown',
                'file' => '',
                'line' => 0,
                'trace' => '',
            );
        }

        $trace = $e->getTraceAsString();
        if (strlen($trace) > 8000) {
            $trace = substr($trace, 0, 8000) . "\n... (truncated)";
        }

        return array(
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => (int) $e->getLine(),
            'trace' => $trace,
        );
    }

    /**
     * 发送 JSON 500 调试响应（与 ApiResponse 规范一致）。
     *
     * @param \Throwable $e 兼容 PHP 7+ 的 Error 与 Exception
     * @return void
     */
    public static function sendApi500($e)
    {
        $message = self::isThrowableLike($e) ? $e->getMessage() : 'server_error';
        ApiResponse::error(
            ApiCodes::SERVER_ERROR,
            $message,
            self::apiDebugErrors($e),
            500
        )->send();
    }
}
