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

use Dou\Core\Facade\View;
use Dou\Core\Foundation\Container\Container;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Foundation\Middleware\MiddlewareRegistry;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Routing\BackendDeclaredMatcher;
use Dou\Core\Web\Routing\DispatchPlan;
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

        // 会员衍生模块闸：features.user 关闭时直接抛 DomainException，避免容器反射到 user 模块下发的类。
        Module::assertUserAvailable($cur);

        $method = MethodResolver::resolve($action, $fqcn);

        $request->setBaseUrl((string) ROOT_URL);
        $request->setRoute($cur, $action, (string) $hit['sub']);
        $request->setRouteParams($hit['params']);
        $request->mergeRouteInputs($hit['params']);
        View::assign('cur', $cur);
        self::assignFormTarget($action, $hit);

        $registry = new MiddlewareRegistry($container, self::$aliasMap);
        $middlewares = $registry->compose(self::$defaultAliases, $hit['entry']);

        return new DispatchPlan($fqcn, $method, $hit['params'], $middlewares);
    }

    /**
     * 渲染 create / edit 表单页时，单点装配表单提交目标 URL 与方法伪装值到视图引擎全局。
     *
     * 表单 action 由本方法单点装配：create 提交到资源 store
     * （POST，URL 无 id），edit 提交到 update（PUT，URL 带成员 id）。目标 URL 由 {@see route()} 按
     * 命中条目的真实 pattern 生成——resource 端得到 `module` / `module/{id}`，group 端得到
     * `prefix/store` / `prefix/update&id=`，两端各自正确。base 为命中条目路由名去掉末段动作。
     *
     * 模板统一用 `{$form_action}`（action 属性）+ `{$form_method}`（隐藏 `_method`，空值由
     * {@see \Dou\Core\Web\Http\Request::method()} 忽略，等价原生 POST）。
     *
     * @param string $action 命中动作（仅 create / edit 装配）
     * @param array $hit 匹配器命中结构（含 entry 与 params）
     * @return void
     */
    private static function assignFormTarget($action, array $hit)
    {
        if ($action !== 'create' && $action !== 'edit') {
            return;
        }
        $name = isset($hit['entry']) ? (string) $hit['entry']->name : '';
        $dot = strrpos($name, '.');
        if ($dot === false) {
            return;
        }
        $base = substr($name, 0, $dot);

        if ($action === 'create') {
            View::assign('form_action', route($base . '.store'));
            View::assign('form_method', '');
            return;
        }

        $params = isset($hit['params']) && is_array($hit['params']) ? $hit['params'] : array();
        View::assign('form_action', route($base . '.update', $params));
        View::assign('form_method', 'PUT');
    }
}
