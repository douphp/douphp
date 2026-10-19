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

namespace Dou\Admin\Foundation\Routing;

use Dou\Admin\Service\Menu\AdminNavResolver;
use Dou\Core\Facade\View;
use Dou\Core\Foundation\Container\Container;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Foundation\Middleware\MiddlewareRegistry;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Routing\BackendDeclaredMatcher;
use Dou\Core\Web\Routing\DispatchPlan;
use Dou\Core\Web\Routing\FormTargetBinder;
use Dou\Core\Web\Routing\MethodResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台端 Resolver（声明式 + 分层中间件）
 *
 * 后台始终以 `?route=module[/id[/action]]` 形态进入；由 {@see BackendDeclaredMatcher}
 * 按 admin/route/*.php 中的 declared 条目（URL 命中 + specificity 排序 + HTTP 方法过滤）
 * 决定控制器 / 方法 / 路径参数，产出 {@see DispatchPlan}。
 *
 * 中间件分层（secure-by-default）：全局默认栈（{@see defaultAliases}）由
 * {@see MiddlewareRegistry::compose} 与命中条目携带的路由级 middleware / withoutMiddleware /
 * 参数覆盖叠加，得到最终中间件实例链。未匹配返回 {@see DispatchPlan::notFound()}（Router 渲染
 * page_wrong 跳转）；URL 命中但方法不被接受返回 {@see DispatchPlan::methodNotAllowed()}（405）。
 */
class AdminResolver
{
    /**
     * 后台全局默认中间件栈（别名有序，secure-by-default）。
     *
     * @var string[]
     */
    private static $defaultAliases = array('security_headers', 'trust_proxy', 'auth', 'permission', 'csrf', 'workspace');

    /**
     * 别名 → 中间件 FQCN 映射。
     *
     * @var array<string,string>
     */
    private static $aliasMap = array(
        'security_headers' => 'Dou\\Admin\\Middleware\\SecurityHeadersMiddleware',
        'trust_proxy' => 'Dou\\Core\\Foundation\\Middleware\\TrustProxyMiddleware',
        'auth' => 'Dou\\Admin\\Middleware\\AuthMiddleware',
        'permission' => 'Dou\\Admin\\Middleware\\PermissionMiddleware',
        'csrf' => 'Dou\\Admin\\Middleware\\CsrfMiddleware',
        'workspace' => 'Dou\\Admin\\Middleware\\AdminWorkspaceMiddleware',
    );

    /**
     * 中间件定义（Resolver 组链与 route-audit 共用同一真值源）。
     *
     * @return array{aliases:array<string,string>,default_aliases:string[]}
     */
    public static function middlewareSpec()
    {
        return array(
            'aliases' => self::$aliasMap,
            'default_aliases' => self::$defaultAliases,
        );
    }

    /**
     * 解析当前后台请求为分发计划。
     *
     * @param Request $request
     * @param Container $container
     * @return DispatchPlan
     */
    public static function resolve(Request $request, Container $container)
    {
        $routeRaw = trim((string) $request->routeString(), '/');

        $hit = BackendDeclaredMatcher::match($routeRaw, 'Admin', $request->method());
        if ($hit === null) {
            return DispatchPlan::notFound();
        }
        if (isset($hit['status']) && $hit['status'] === 'method_not_allowed') {
            return DispatchPlan::methodNotAllowed($hit['allow']);
        }

        $cur = $hit['module'];
        $action = $hit['action'];
        $fqcn = $hit['fqcn'];

        if ($fqcn === null || $fqcn === '' || !class_exists($fqcn)) {
            Log::warning('Admin route dispatch failed', array(
                'channel' => 'route',
                'route' => $routeRaw,
                'module' => $cur,
                'action' => $action,
                'controller' => (string) $fqcn,
            ));

            return DispatchPlan::notFound();
        }

        // 会员衍生模块闸：features.user 关闭时直接抛 DomainException，避免容器反射到 user 模块下发的类。
        Module::assertUserAvailable($cur);

        $method = MethodResolver::resolve($action, $fqcn);

        $request->setBaseUrl((string) ROOT_URL);
        $request->setRoute($cur, $action, (string) $hit['sub'], $hit['entry']);
        $request->setRouteParams($hit['params']);
        $request->mergeRouteInputs($hit['params']);
        View::assign('cur', $cur);
        // $nav 契约：按命中路由名中央解析（侧栏 / 二级菜单 / 面包屑数据源）
        View::assign('nav', AdminNavResolver::resolve((string) $hit['entry']->name));
        FormTargetBinder::assign(
            $action,
            isset($hit['entry']) ? (string) $hit['entry']->name : '',
            isset($hit['params']) && is_array($hit['params']) ? $hit['params'] : array()
        );

        $registry = new MiddlewareRegistry($container, self::$aliasMap);
        $middlewares = $registry->compose(self::$defaultAliases, $hit['entry']);

        return new DispatchPlan($fqcn, $method, $hit['params'], $middlewares);
    }

}
