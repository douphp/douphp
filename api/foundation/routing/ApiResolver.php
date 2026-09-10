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

namespace Dou\Api\Foundation\Routing;

use Dou\Core\Foundation\Configuration\Config;
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
 * API 端 Resolver（JSON，声明式 + 分层中间件）
 *
 * 与后台同样以 `?route=module[/id[/action]]` 形态进入；由 {@see BackendDeclaredMatcher} 按
 * api/route/*.php 中的 declared 条目（URL 命中 + specificity 排序 + HTTP 方法过滤）决定控制器 /
 * 方法 / 路径参数，产出 {@see DispatchPlan}。中间件分层经 {@see MiddlewareRegistry::compose}
 * 在全局默认栈上叠加路由级细化。未匹配返回 {@see DispatchPlan::notFound()}（404 JSON）；
 * URL 命中但方法不被接受返回 {@see DispatchPlan::methodNotAllowed()}（405 JSON）。
 */
class ApiResolver
{
    /**
     * 别名 → 中间件 FQCN 映射。
     *
     * @var array<string,string>
     */
    private static $aliasMap = array(
        'security_headers' => 'Dou\\Api\\Middleware\\SecurityHeadersMiddleware',
        'trust_proxy' => 'Dou\\Core\\Foundation\\Middleware\\TrustProxyMiddleware',
        'throttle' => 'Dou\\Api\\Middleware\\ThrottleMiddleware',
        'user_auth' => 'Dou\\Api\\Middleware\\UserAuthMiddleware',
    );

    /**
     * 解析当前 API 请求为分发计划。
     *
     * @param Request $request
     * @param Container $container
     * @return DispatchPlan
     */
    public static function resolve(Request $request, Container $container)
    {
        $routeRaw = trim((string) $request->routeString(), '/');

        $hit = BackendDeclaredMatcher::match($routeRaw, 'Api', $request->method());
        if ($hit === null) {
            return DispatchPlan::notFound();
        }
        if (isset($hit['status']) && $hit['status'] === 'method_not_allowed') {
            return DispatchPlan::methodNotAllowed($hit['allow']);
        }

        $cur = $hit['module'];
        $action = $hit['action'];
        $fqcn = $hit['fqcn'];
        $sub = (string) $hit['sub'];

        // 会员衍生模块闸：features.user 关闭时直接抛 DomainException，避免容器反射到 user 模块下发的类。
        Module::assertUserAvailable($cur);

        $method = MethodResolver::resolve($action, $fqcn);

        $request->setBaseUrl((string) (defined('ROOT_URL') ? ROOT_URL : ''));
        $request->setRoute($cur, $action, $sub);
        $request->setRouteParams($hit['params']);
        $request->mergeRouteInputs($hit['params']);

        $registry = new MiddlewareRegistry($container, self::$aliasMap);
        $middlewares = $registry->compose(self::defaultAliases(), $hit['entry']);

        return new DispatchPlan($fqcn, $method, $hit['params'], $middlewares);
    }

    /**
     * API 全局默认中间件栈（别名有序）。
     *
     * user_auth 仅在 features.user 开启时进入默认栈（类缺失时 Registry 再兜底跳过）。
     *
     * @return string[]
     */
    private static function defaultAliases()
    {
        $aliases = array('security_headers', 'trust_proxy', 'throttle');
        if (Config::get('features.user', false)) {
            $aliases[] = 'user_auth';
        }
        return $aliases;
    }
}
