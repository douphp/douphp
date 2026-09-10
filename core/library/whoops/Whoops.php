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

namespace Dou\Vendor\Whoops;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * Whoops 第三方调试库门面。
 *
 * 仅在 site.debug 为真的场景被调用：
 * - {@see Whoops::render} 由 {@see \Dou\Core\Foundation\Exception\SiteDebugExceptionRenderer::renderAndExitForHtml}
 *   走显式 try/catch 链路时调用，直接输出 PrettyPage 并结束请求。
 * - {@see Whoops::registerGlobal} 由 {@see \Dou\Core\Init\InitTrait::applySiteDebugIni} 在
 *   debug 开启且 {@see \Dou\Core\Foundation\Exception\SiteDebugExceptionRenderer::shouldRegisterWhoopsGlobal}
 *   为真时调用（非 API、非 JSON/Ajax 请求），由 Whoops 接管全局 handler。
 *
 * 上游 PSR-4 `Whoops\*` 命名空间保持原状，仅在本类内部按需懒注册到
 * `core/library/whoops/src/Whoops/`，便于跟随上游升级。
 */
class Whoops
{
    /**
     * PSR-4 自动加载是否已经注册过。
     *
     * @var bool
     */
    private static $loaded = false;

    /**
     * 懒注册 `Whoops\` 前缀的 PSR-4 自动加载（幂等）。
     *
     * @return void
     */
    private static function ensureLoaded()
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $base = LIBRARY_PATH . 'whoops/src/Whoops/';
        spl_autoload_register(function ($className) use ($base) {
            if (strncmp($className, 'Whoops\\', 7) !== 0) {
                return;
            }
            $relative = substr($className, 7);
            $classFile = $base . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($classFile)) {
                require_once $classFile;
            }
        });
    }

    /**
     * 输出 Whoops PrettyPage 并结束请求（HTTP 500）。
     *
     * 与 {@see \Dou\Core\Foundation\Exception\SiteDebugExceptionRenderer::renderAndExitForHtml}
     * 行为一致：清空已有输出缓冲、按需设置 500 响应头、把 $channel 编入页面标题。
     *
     * @param mixed  $e       PHP 7 起的 \Throwable，或 PHP 5.x 兼容的 \Exception。
     * @param string $channel 来源标识，例如 front / admin / app。
     * @return void
     */
    public static function render($e, $channel = 'app')
    {
        // 上游 Whoops 2.x 为 PHP 7+ 库：PHP 5.6 一律不加载，交由调用方
        // （SiteDebugExceptionRenderer）回退到 plain HTML 调试页。
        if (PHP_VERSION_ID < 70000) {
            return;
        }
        if (!is_object($e)) {
            return;
        }
        if (!($e instanceof \Exception) && !(interface_exists('Throwable') && $e instanceof \Throwable)) {
            return;
        }

        self::ensureLoaded();

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: text/html; charset=utf-8');
        }

        $run = new \Whoops\Run();
        $handler = new \Whoops\Handler\PrettyPageHandler();

        $label = (string) $channel;
        if ($label === '') {
            $label = 'app';
        }
        $handler->setPageTitle('[' . $label . '] ' . get_class($e) . ': ' . $e->getMessage());

        $run->appendHandler($handler);
        $run->handleException($e);
        exit;
    }

    /**
     * 在 site.debug 开启时由 InitTrait 调用：把全局 PHP 错误/未捕获异常/shutdown
     * 兜底统一交给 Whoops PrettyPage。
     *
     * 会覆盖 InitTrait::registerExceptionHandlers 之前注册的 set_exception_handler。
     * 仅应在 HTML 页面请求场景调用；API/JSON 由 SiteDebugExceptionRenderer::sendApi500 处理。
     *
     * @return void
     */
    public static function registerGlobal()
    {
        // 上游 Whoops 2.x 为 PHP 7+ 库：PHP 5.6 不注册全局 handler，
        // 未捕获异常仍由 InitTrait::handleGlobalException + plain HTML 兜底。
        if (PHP_VERSION_ID < 70000) {
            return;
        }

        self::ensureLoaded();

        $run = new \Whoops\Run();
        $run->appendHandler(new \Whoops\Handler\PrettyPageHandler());
        $run->register();
    }
}
