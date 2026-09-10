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
 * 中间件管道
 *
 * 将多个中间件组合成链式调用：
 *   Pipeline::make([new AuthMiddleware(...), new PermissionMiddleware(...)])
 *       ->run(function() { $controller->action(); });
 *
 * 兼容 PHP 5.6–8.5。
 */
class MiddlewarePipeline
{
    /** @var MiddlewareInterface[] */
    private $middlewares;

    /**
     * @param MiddlewareInterface[] $middlewares 按执行顺序排列
     */
    public function __construct(array $middlewares = array())
    {
        $this->middlewares = $middlewares;
    }

    /**
     * 静态工厂方法。
     *
     * @param MiddlewareInterface[] $middlewares
     * @return static
     */
    public static function make(array $middlewares = array())
    {
        return new static($middlewares);
    }

    /**
     * 执行管道，最终调用 $then。
     *
     * @param callable $then 最终处理器（控制器动作）
     * @return mixed
     */
    public function run($then)
    {
        $chain = array_reduce(
            array_reverse($this->middlewares),
            function ($carry, $middleware) {
                return function () use ($middleware, $carry) {
                    return $middleware->handle($carry);
                };
            },
            $then
        );

        return $chain();
    }
}
