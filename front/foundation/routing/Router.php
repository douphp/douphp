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

use Dou\Core\Foundation\Container\Container;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\Routing\Dispatcher;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台路由调度器（薄壳）
 *
 * 只读 Request（路由字符串已由前台入口 LangPrefixParser 剥去语言前缀），交 {@see FrontResolver}
 * 产出 {@see \Dou\Core\Web\Routing\DispatchPlan}，再交中央 {@see Dispatcher} 执行；未匹配（404）时
 * 渲染前台 page_wrong 提示。路由解析 / 子控制器 FQCN 推导 / 参数注入全部收口在 FrontResolver。
 */
class Router
{
    /**
     * 执行 dispatch 操作。
     *
     * @return Response|null 控制器 return Response 时交由入口 send；否则 null
     */
    public function dispatch()
    {
        $container = Container::getInstance();
        $request = $container->make(Request::class);

        $plan = FrontResolver::resolve($request, $container);
        if ($plan->isNotFound()) {
            return message()->respond('page_wrong', HOME_URL);
        }

        $dispatched = Dispatcher::run($plan, $container);
        if ($dispatched instanceof Response) {
            return $dispatched;
        }

        return null;
    }
}
