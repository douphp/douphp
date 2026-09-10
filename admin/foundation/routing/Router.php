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

use Dou\Core\Foundation\Container\Container;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\Routing\Dispatcher;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台路由调度器（薄壳）
 *
 * URL 格式：admin/index.php?route=article/edit；路径中间数字段即 id（route=product/123/edit）。
 * 只读 Request（路由字符串由入口写入），交 {@see AdminResolver} 经位置解析产出
 * {@see \Dou\Core\Web\Routing\DispatchPlan}，再交中央 {@see Dispatcher} 在中间件管道内执行；
 * 未匹配（404）时重定向到后台首页并携带 page_wrong 提示。路由参数经 Request 独立路由袋注入，不写超全局。
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

        $plan = AdminResolver::resolve($request, $container);
        if ($plan->isMethodNotAllowed()) {
            $response = redirect(route('admin.index'))->with('error', lang('page_wrong'));
            if ($plan->allowedMethods) {
                $response->setHeader('Allow', implode(', ', $plan->allowedMethods));
            }
            return $response;
        }
        if ($plan->isNotFound()) {
            return redirect(route('admin.index'))->with('error', lang('page_wrong'));
        }

        $dispatched = Dispatcher::run($plan, $container);
        if ($dispatched instanceof Response) {
            return $dispatched;
        }

        return null;
    }
}
