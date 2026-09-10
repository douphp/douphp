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

namespace Dou\Core\Web\Routing;

use Dou\Core\Foundation\Container\Container;
use Dou\Core\Web\Http\Request;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 入站路由调度器：三端 Router 的统一薄包装。
 *
 * 三端 index.php 入口均通过 {@see \Dou\Core\Facade\Route} 静态门面调用本类。
 * 本类不实现路由解析与分发，只承载「当前路由状态」与对三端各自 Router 的统一代理：
 *
 *   - `setDelegate(...)` 接收 front/admin/api 各自的具体 Router（`Dou\{Front|Admin|Api}\Foundation\Routing\Router`）。
 *   - `dispatch()` 透传 delegate->dispatch()。
 *   - `current()` 全量读容器中 Request：module/action/sub 来自 dispatch 阶段 setRoute，route/lang 来自入口边界写入的 routeString/routeLangSign，is_home 由 routeString === '' 派生。
 *
 * 设计取舍：
 *   - 不暴露注册类方法（`get/post/middleware/group/name`）：DouPHP 采用约定式路由，
 *     注册口在 `{front|admin|api}/init/route.php` 与 `*RouteRules`，与编程式注册模型不一致。
 *   - 三端 Router 类（`front/foundation/routing/Router.php` 等）作为本类的 delegate。
 */
class DelegatingRouter
{
    /** @var object|null 三端具体 Router 实例（front/admin/api） */
    private $delegate = null;

    /**
     * 注入三端具体 Router 作为 delegate。
     *
     * @param object $delegate front/admin/api Router 实例
     * @return $this
     */
    public function setDelegate($delegate)
    {
        $this->delegate = $delegate;
        return $this;
    }

    /**
     * 透传 delegate->dispatch()。
     *
     * @return mixed Response|null
     */
    public function dispatch()
    {
        if ($this->delegate === null) {
            return null;
        }
        return $this->delegate->dispatch();
    }

    /**
     * 当前路由信息：array('module', 'action', 'sub', 'route', 'lang', 'is_home')。
     *
     * 全量从容器中的 Request 读取：module/action/sub 由 dispatch 阶段端 Resolver setRoute 写入；
     * route/lang 由前台入口边界（LangPrefixParser）写入的 routeString/routeLangSign 提供，
     * 便于 `Init::boot(Route::current())` 取 `lang` / `is_home` 等多语言初始化信息；
     * is_home 由 routeString === '' 派生（剥语言前缀后空 route 即首页）。
     *
     * @return array
     */
    public function current()
    {
        $module = '';
        $action = '';
        $sub = '';
        $route = '';
        $lang = '';
        $isHome = false;

        if (Container::getInstance()->bound(Request::class)) {
            /** @var Request $request */
            $request = Container::getInstance()->make(Request::class);
            $module = (string) $request->routeModule();
            $action = (string) $request->routeAction();
            $sub = (string) $request->routeSub();
            $route = (string) $request->routeString();
            $lang = (string) $request->routeLangSign();
            $isHome = ($route === '');
        }

        return array(
            'module' => $module,
            'action' => $action,
            'sub' => $sub,
            'route' => $route,
            'lang' => $lang,
            'is_home' => $isHome,
        );
    }
}
