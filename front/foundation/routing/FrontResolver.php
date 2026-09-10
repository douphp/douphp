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

namespace Dou\Front\Foundation\Routing;

use Dou\Core\Facade\View;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Container\Container;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Foundation\Middleware\MiddlewareRegistry;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Routing\DispatchPlan;
use Dou\Core\Web\Routing\MethodResolver;
use Dou\Front\Service\Init\ThemeExtensionLoader;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台端 Resolver（纯声明式）
 *
 * 以 {@see PrettyRouteMatcher} 唯一外观 URL 解析来源匹配前台 declared 条目（系统内置端点 +
 * front/route/*.php 业务条目），直接读取命中条目的显式 controller FQCN。
 *
 * 首页空串经匹配器短路给出 IndexController；llms.txt / sitemap.xml / captcha / search /
 * plugin 等系统内置端点已在 manifest 中以 declared 条目承载，无需特殊分支。
 *
 * 路由字符串已在前台入口由 LangPrefixParser 剥去语言前缀（首页即空串）。
 */
class FrontResolver
{
    /**
     * 解析当前前台请求为分发计划。
     *
     * @param Request $request
     * @param Container $container
     * @return DispatchPlan
     */
    public static function resolve(Request $request, Container $container)
    {
        $route = (string) $request->routeString();

        $result = $container->make(PrettyRouteMatcher::class)->normalize($route, $request->method());

        if (empty($result['matched'])) {
            Log::warning('Front route unmatched', array(
                'channel' => 'route',
                'route' => $route,
                'lang' => $request->routeLangSign(),
                'is_home' => ($route === '') ? 1 : 0,
            ));

            return DispatchPlan::notFound();
        }

        $routeModule = (string) $result['module'];
        $routeAction = (string) $result['action'];
        $routeSub = (string) $result['sub'];
        $params = isset($result['params']) && is_array($result['params']) ? $result['params'] : array();
        $fqcn = isset($result['controller']) ? $result['controller'] : null;

        if ($fqcn === null || $fqcn === '' || !class_exists($fqcn)) {
            Log::warning('Front route dispatch failed', array(
                'channel' => 'route',
                'route' => $route,
                'module' => $routeModule,
                'action' => $routeAction,
                'sub' => $routeSub,
                'controller' => (string) $fqcn,
            ));

            return DispatchPlan::notFound();
        }

        // 会员衍生模块闸：features.user 关闭时直接抛 DomainException，避免容器反射到 user 模块下发的类。
        Module::assertUserAvailable($routeModule);

        // 全请求统一进入声明式安全栈（含首页与 llms.txt）：默认栈对它们天然 no-op
        // （CSRF GET 放行、throttle 无配额放行、user_auth 未登记按 optional 不拦匿名），
        // 但 SecurityHeaders / TrustProxy 等全局中间件不再被无意跳过。
        // 路由级豁免（如支付回调 plugin/notify|finish 的 withoutMiddleware(['csrf'])）仍由命中条目带出。
        $middlewares = self::composeMiddlewares($container, $result);

        $request->setBaseUrl((string) (defined('ROOT_URL') ? ROOT_URL : ''));
        $request->setRoute($routeModule, $routeAction, $routeSub);
        $request->setRouteParams($params);
        $request->mergeRouteInputs($params);
        View::assign('cur', $routeModule);
        self::assignFormTarget($routeAction, $result, $params);
        self::loadThemeExtension($container, $routeModule, $routeAction);

        return new DispatchPlan($fqcn, MethodResolver::resolve($routeAction, $fqcn), $params, $middlewares);
    }

    /**
     * 渲染会员中心 create / edit 表单页时，单点装配表单提交目标 URL 与方法伪装值到视图引擎全局。
     *
     * create 提交到资源 store（POST，URL 无 id），edit 提交到 update（PUT，URL 带成员 id）。逻辑与
     * {@see \Dou\Admin\Foundation\Routing\AdminResolver::assignFormTarget()} 相同。目标 URL 由
     * {@see route()} 按命中条目路由名去末段动作得到的 base 生成；模板统一用 `{$form_action}`
     * （action 属性）+ `{$form_method}`（隐藏 `_method`，空值由 {@see \Dou\Core\Web\Http\Request::method()}
     * 忽略，等价原生 POST）。仅资源条目（路由名形如 `module.sub.action`）参与，普通 group/column
     * 条目无 create/edit 动作不受影响。
     *
     * @param string $action 命中动作（仅 create / edit 装配）
     * @param array $result 匹配器命中结构（含 name 路由名）
     * @param array $params 路径捕获的具名参数（edit 的成员 id）
     * @return void
     */
    private static function assignFormTarget($action, array $result, array $params)
    {
        if ($action !== 'create' && $action !== 'edit') {
            return;
        }
        $name = isset($result['name']) ? (string) $result['name'] : '';
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

        View::assign('form_action', route($base . '.update', $params));
        View::assign('form_method', 'PUT');
    }

    /**
     * 在控制器执行之前加载主题包 inc/..from_theme.php（此时 routeModule/routeAction 已确定）。
     *
     * @param Container $container
     * @param string $routeModule
     * @param string $routeAction
     * @return void
     */
    private static function loadThemeExtension(Container $container, $routeModule, $routeAction)
    {
        $container->make(ThemeExtensionLoader::class)
            ->loadForRoute((string) $routeModule, (string) $routeAction);
    }

    /**
     * 解析当前请求需挂载的中间件链（声明式：默认栈 + 命中条目路由级细化）。
     *
     * 默认栈顺序（secure-by-default）：安全头 -> 可信代理 -> 定向限流 -> [会员认证] -> CSRF。
     * 限流须在 TrustProxy 之后以确保 ip 可信；会员认证仅在 features.user 开启时入栈，且
     * 中间件类缺失（user 模块包未安装）时由 {@see MiddlewareRegistry} 吞掉跳过，不 fatal。
     *
     * 路由级豁免（如支付回调 plugin/notify|finish 的 `->withoutMiddleware(['csrf'])`）经
     * PrettyRouteMatcher 命中条目带出的 mw_* 字段交由 registry 过滤，与 admin 端共用同一套
     * 组装语义。
     *
     * @param Container $container
     * @param array $result PrettyRouteMatcher::normalize 结果（含 mw_* 路由级细化字段）
     * @return array
     */
    private static function composeMiddlewares(Container $container, array $result)
    {
        $aliasMap = array(
            'security_headers' => 'Dou\\Front\\Middleware\\SecurityHeadersMiddleware',
            'trust_proxy' => 'Dou\\Core\\Foundation\\Middleware\\TrustProxyMiddleware',
            'throttle' => 'Dou\\Front\\Middleware\\ThrottleMiddleware',
            'user_auth' => 'Dou\\Front\\Middleware\\UserAuthMiddleware',
            'csrf' => 'Dou\\Front\\Middleware\\CsrfMiddleware',
        );

        $defaultAliases = array('security_headers', 'trust_proxy', 'throttle');
        if (Config::get('features.user', false)) {
            $defaultAliases[] = 'user_auth';
        }
        $defaultAliases[] = 'csrf';

        $registry = new MiddlewareRegistry($container, $aliasMap);

        return $registry->composeFromSpec(
            $defaultAliases,
            isset($result['mw_skip_all']) ? (bool) $result['mw_skip_all'] : false,
            isset($result['mw_without']) && is_array($result['mw_without']) ? $result['mw_without'] : array(),
            isset($result['mw_append']) && is_array($result['mw_append']) ? $result['mw_append'] : array(),
            isset($result['mw_params']) && is_array($result['mw_params']) ? $result['mw_params'] : array()
        );
    }
}
