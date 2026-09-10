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

namespace Dou\Front\Service\Init;

use Dou\Core\Facade\Portal;
use Dou\Core\Foundation\Container\Container;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;
use Dou\Core\Web\Template\DouView;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 主题扩展加载器。
 *
 * 负责加载主题包内的 `inc/..from_theme.php`：在 {@see \Dou\Front\Foundation\Routing\Router::dispatch()}
 * 解析出当前 `module/action` 之后、控制器执行之前调用，以便主题脚本能基于当前路由分支决定要塞什么数据。
 *
 * 主题脚本一律以静态门面 {@see Portal} 访问能力（`Portal::columnList()` / `Portal::singleList()` /
 * `Portal::categoryTree()` / `Portal::assign()` / `Portal::routeModule()` 等）。
 * include 前调用 {@see Portal::boot()} 写入赋值目标（DouView）与当前路由上下文。
 */
class ThemeExtensionLoader extends BaseService
{
    /**
     * 在当前路由已知后加载主题 `inc/..from_theme.php`（若存在且通过 SQL 关键字白名单）。
     *
     * 调用时机由 {@see \Dou\Front\Foundation\Routing\Router::dispatch()} 控制，
     * 确保 Request 已写入 routeModule/routeAction；以便主题脚本依据当前路由分支
     * 决定要塞什么模板变量（避免每个 URL 都执行全部 assign）。
     *
     * @param string $routeModule 当前路由模块，由 Router 显式传入
     * @param string $routeAction 当前路由动作，由 Router 显式传入
     * @return void
     */
    public function loadForRoute($routeModule = '', $routeAction = '')
    {
        if (!Container::getInstance()->has(DouView::class)) {
            return;
        }

        $engine = app(DouView::class);
        $path = $engine->template_dir . '/inc/..from_theme.php';
        if (!file_exists($path)) {
            return;
        }
        if (!Util::isSqlSafePhpFile($path)) {
            return;
        }

        Portal::boot((string) $routeModule, (string) $routeAction, $engine);

        include_once($path);
    }
}
