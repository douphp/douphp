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

namespace Dou\Core\Foundation\Middleware;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 中间件接口
 *
 * 所有中间件必须实现此接口。$next 是链中下一个可调用对象（闭包），中间件负责在适当时机
 * 调用它（或不调用，以阻断后续链路 / 抛 HttpResponseException 短路返回响应）。
 *
 * **放行契约**：放行时必须 `return $next();`，由 {@see MiddlewarePipeline} 负责将下层返回值
 * 原样冒泡到顶。中间件**不得**调用 `$next()` 后丢弃其返回值（典型反模式是
 * 「`$result = $next(); if ($result instanceof Response) return $result;` 末尾隐式 return null」
 * 会丢失控制器 Response 导致响应被吃掉）。
 */
interface MiddlewareInterface
{
    /**
     * 处理请求并调用下一个中间件或最终控制器动作。
     *
     * @param callable $next 下一个处理器
     * @return mixed 下层返回值（控制器返回的 Response 等）；放行须 `return $next();` 原样冒泡
     */
    public function handle($next);
}
