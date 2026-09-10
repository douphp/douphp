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
use Dou\Core\Foundation\Middleware\MiddlewarePipeline;
use Dou\Core\Web\Http\Request;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 中央分发器。
 *
 * 收到端 Resolver 产出的 {@see DispatchPlan}：在中间件管道内懒实例化控制器并调用方法。
 * FQCN / method 由端 Resolver 决定，本类不做路由决策、不做 method_exists 兜底、不渲染
 * HTTP 响应；未匹配（`fqcn` 为空）时直接返回 null，交由各端 Router 渲染端专属 404。
 */
class Dispatcher
{
    /**
     * 执行分发计划。
     *
     * @param DispatchPlan $plan
     * @param Container $container
     * @return mixed 控制器返回值（常为 null 或 Response）；未匹配时返回 null
     */
    public static function run(DispatchPlan $plan, Container $container)
    {
        if ($plan->isNotFound()) {
            return null;
        }

        // 路由参数写入收口于此：在中间件管道之前注入 Request 路由袋，
        // 确保中间件与控制器都能经 Request::route() 取到路径段参数。
        $container->make(Request::class)->setRouteParams($plan->params);

        $fqcn = $plan->fqcn;
        $method = $plan->method;

        return MiddlewarePipeline::make($plan->middlewares)->run(function () use ($container, $fqcn, $method) {
            $controller = $container->make($fqcn);

            return $container->call($controller, $method, array('__scene' => $method));
        });
    }
}
