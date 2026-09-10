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

use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Container\Container;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\Routing\Dispatcher;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * HTTP API 路由调度器（JSON，薄壳）
 *
 * URL：api/index.php?route=module/action；路径中间数字段即 id。只读 Request（路由字符串由入口写入），
 * 交 {@see ApiResolver} 经位置解析产出 {@see \Dou\Core\Web\Routing\DispatchPlan}，再交中央
 * {@see Dispatcher} 在中间件管道内执行；未匹配（含路由非法 / 未找到）统一渲染 NOT_FOUND 404 JSON。
 * 路由参数经 Request 独立路由袋注入，不写超全局。
 */
class Router
{
    /**
     * 执行 dispatch 操作。
     *
     * @return Response|null
     */
    public function dispatch()
    {
        $container = Container::getInstance();
        $request = $container->make(Request::class);

        $plan = ApiResolver::resolve($request, $container);
        if ($plan->isMethodNotAllowed()) {
            return ApiResponse::error(
                ApiCodes::NOT_FOUND,
                'Method Not Allowed',
                array(),
                405,
                array('allow' => $plan->allowedMethods)
            );
        }
        if ($plan->isNotFound()) {
            return ApiResponse::error(ApiCodes::NOT_FOUND, 'Not Found', array(), 404);
        }

        $dispatched = Dispatcher::run($plan, $container);
        if ($dispatched instanceof Response) {
            return $dispatched;
        }

        return null;
    }
}
