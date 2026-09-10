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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 路由分发计划（不可变值对象）
 *
 * 端 Resolver 解析请求后产出的唯一最终产物：已完成「composite > map > convention」三优先级
 * 决策，`fqcn` / `method` 为可直接执行的确定值；`fqcn === null` 表示未匹配（404），由各端
 * Router 渲染端专属的 404 响应。中央 {@see Dispatcher} 仅按 plan 执行。
 *
 * 当前路由模块 / 动作 / 子段在 Resolver 内已经过 `$request->setRoute(...)` 写入 Request，
 * 由下游通过 `Request::routeModule()` 等读取。
 *
 * PHP 5.6 安全：构造期赋值 public 属性，不使用类型化属性 / readonly / nullable 声明。
 */
class DispatchPlan
{
    /** @var string|null 最终控制器 FQCN；null 表示未匹配（404） */
    public $fqcn;

    /** @var string|null 最终控制器方法名 */
    public $method;

    /** @var array 路径参数（写入 Request 独立路由袋） */
    public $params;

    /** @var array 中间件实例列表 */
    public $middlewares;

    /** @var bool 是否方法不允许（405）：URL 命中但 HTTP 方法不在白名单 */
    public $methodNotAllowed;

    /** @var string[] 405 时该 URL 支持的方法集合（渲染 Allow 头用） */
    public $allowedMethods;

    /**
     * @param string|null $fqcn
     * @param string|null $method
     * @param array $params
     * @param array $middlewares
     */
    public function __construct($fqcn, $method, array $params = array(), array $middlewares = array())
    {
        $this->fqcn = $fqcn;
        $this->method = $method;
        $this->params = $params;
        $this->middlewares = $middlewares;
        $this->methodNotAllowed = false;
        $this->allowedMethods = array();
    }

    /**
     * 未匹配（404）计划：fqcn 为 null，由各端 Router 渲染端专属 404。
     *
     * @return self
     */
    public static function notFound()
    {
        return new self(null, null);
    }

    /**
     * 方法不允许（405）计划：URL 命中但 HTTP 方法不被接受，由各端 Router 渲染 405 + Allow 头。
     *
     * @param string[] $allow 该 URL 支持的方法集合
     * @return self
     */
    public static function methodNotAllowed(array $allow = array())
    {
        $plan = new self(null, null);
        $plan->methodNotAllowed = true;
        $plan->allowedMethods = $allow;
        return $plan;
    }

    /**
     * 是否未匹配（404）。注意 405 计划 fqcn 也为 null，故消费方应先判 405 再判 404。
     *
     * @return bool
     */
    public function isNotFound()
    {
        return $this->fqcn === null || $this->fqcn === '';
    }

    /**
     * 是否方法不允许（405）。
     *
     * @return bool
     */
    public function isMethodNotAllowed()
    {
        return $this->methodNotAllowed === true;
    }
}
