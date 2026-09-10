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

namespace Dou\Install\Foundation\Routing;

use Dou\Install\Foundation\Context\InstallContext;
use Dou\Install\Service\InstallLockService;
use Dou\Install\Support\Helper;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * install 路由调度器。
 *
 * URL 形态：`install/index.php?route=check` 或 `?route=install/post`
 * - 单段（如 `check`）→ 控制器 index 方法
 * - 双段（如 `install/post`）→ 控制器对应方法（snake_case → camelCase）
 *
 * 若 `storage/install.lock` 已存在，所有路由统一强制走 `lock`（对标 doubak 的 DB
 * 不可达时强制 `config`）。
 */
class Router
{
    /** @var array 路由映射表：module => 控制器 FQCN */
    private $map;

    /**
     * @param array $map
     */
    public function __construct(array $map)
    {
        $this->map = $map;
    }

    /**
     * 解析 `?route=` 并调度对应控制器方法。
     *
     * @param InstallContext $ctx
     * @return void
     */
    public function dispatch(InstallContext $ctx)
    {
        $route = isset($_REQUEST['route']) ? (string) $_REQUEST['route'] : '';
        $route = trim($route, '/');

        $lockService = new InstallLockService();
        if ($lockService->isLocked() && $route !== 'lock') {
            $route = 'lock';
        }

        if ($route === '') {
            $route = 'index';
        }

        $module = $route;
        $action = 'index';
        if (strpos($route, '/') !== false) {
            $segments = explode('/', $route, 2);
            $module = strtolower($segments[0]);
            $action = $segments[1] !== '' ? strtolower($segments[1]) : 'index';
        } else {
            $module = strtolower($route);
        }

        if (!isset($this->map[$module])) {
            Helper::douMsg($ctx->view, $ctx->lang, isset($ctx->lang['wrong']) ? $ctx->lang['wrong'] : 'Illegal request', 'index.php');
        }

        $controllerClass = $this->map[$module];
        if (!class_exists($controllerClass)) {
            Helper::douMsg($ctx->view, $ctx->lang, isset($ctx->lang['wrong']) ? $ctx->lang['wrong'] : 'Illegal request', 'index.php');
        }

        $method = $this->resolveMethod($action);
        $controller = new $controllerClass($ctx);
        if (!is_callable(array($controller, $method))) {
            Helper::douMsg($ctx->view, $ctx->lang, isset($ctx->lang['wrong']) ? $ctx->lang['wrong'] : 'Illegal request', 'index.php');
        }
        $controller->{$method}();
    }

    /**
     * snake_case 路径动作转 camelCase 方法名。
     *
     * @param string $action
     * @return string
     */
    private function resolveMethod($action)
    {
        $action = preg_replace('/[^a-z0-9_]/', '', $action);
        if ($action === '') {
            return 'index';
        }
        $parts = explode('_', $action);
        $head = array_shift($parts);
        $method = $head;
        foreach ($parts as $part) {
            if ($part !== '') {
                $method .= ucfirst($part);
            }
        }
        return $method;
    }
}
