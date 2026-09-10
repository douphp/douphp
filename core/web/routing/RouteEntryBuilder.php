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
 * 单条声明式路由的链式 builder（由 {@see Route::get/post/put/patch/delete/match/any} 创建）
 *
 * 承载一条 pattern → controller/action 映射 + HTTP 方法白名单 + 路由级中间件 DSL，
 * 构造结束（__destruct）或显式 register() 时推入当前 {@see RouteCollector}。
 *
 * 与 {@see RouteResourceBuilder} 的关系：resource 批量展开 CRUD 集，本类承载单条任意 pattern
 * 的细粒度声明（非 CRUD 工具动作、登录/验证码等需要显式方法或豁免中间件的路由）。
 */
class RouteEntryBuilder
{
    use RouteMiddlewareDsl;
    use RouteNameResolution;

    /** @var RouteCollector */
    private $collector;

    /** @var string */
    private $pattern;

    /** @var string 已归一化前导反斜杠的 FQCN */
    private $controller;

    /** @var string */
    private $action;

    /** @var string */
    private $module;

    /** @var array */
    private $params = array();

    /** @var string|null */
    private $sub = null;

    /** @var string[] HTTP 方法白名单（空 = permissive） */
    private $methods;

    /** @var bool */
    private $registered = false;

    /**
     * @param RouteCollector $collector 当前路由文件累积器
     * @param string[] $methods HTTP 方法白名单（空数组表示 permissive）
     * @param string $pattern URL pattern（PrettyUrlCompiler 迷你语言）
     * @param string $controller 控制器 FQCN
     * @param string $action 控制器动作段
     * @param string $module 路由模块名
     * @param string|null $name 路由名（点分），null 时由 module + action 派生
     */
    public function __construct(RouteCollector $collector, array $methods, $pattern, $controller, $action, $module, $name = null)
    {
        $this->collector = $collector;
        $this->methods = $methods;
        $this->pattern = (string) $pattern;
        $this->controller = '\\' . ltrim((string) $controller, '\\');
        $this->action = (string) $action;
        $this->module = (string) $module;
        $this->nameArg = ($name === null || $name === '') ? null : (string) $name;
    }

    /**
     * 设置路由名。尾点（如 'api.'）= 前缀，叠加到默认 localBase；无尾点 = 全量覆盖。
     *
     * @param string $name
     * @return self
     */
    public function name($name)
    {
        $this->nameArg = (string) $name;
        return $this;
    }

    /**
     * 标记 compositeModule：emit 的 module 字段取复合名 module_sub（仅 sub 非空时生效）。
     *
     * @return self
     */
    public function compositeModule()
    {
        $this->composite = true;
        return $this;
    }

    /**
     * 设置占位符正则映射（如 ['id' => '\d+']）。
     *
     * @param array $params
     * @return self
     */
    public function params(array $params)
    {
        $this->params = $params;
        return $this;
    }

    /**
     * 设置子控制器段。
     *
     * @param string $sub
     * @return self
     */
    public function sub($sub)
    {
        $this->sub = (string) $sub;
        return $this;
    }

    /**
     * 显式注册（按当前配置展开成一条 RouteEntry 推入 collector）。幂等。
     *
     * @return void
     */
    public function register()
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;

        $defaultLocalBase = $this->actionTakesBaseName($this->action) ? $this->module : ($this->module . '.' . $this->action);
        $name = $this->resolveDeclaredName($this->collector, $defaultLocalBase);

        $fields = array(
            'name' => $name,
            'route_type' => 'declared',
            'pattern' => $this->pattern,
            'params' => $this->params,
            'module' => $this->resolveEmittedModule($this->collector, $this->module, $this->sub),
            'controller' => $this->controller,
            'action' => $this->action,
            'sub' => $this->sub,
            'methods' => $this->methods,
            'is_short_url_aware' => false,
            'is_family' => false,
            'source' => $this->collector->sourceFor($name),
        );
        $fields = array_merge($fields, $this->middlewareFields());

        $this->collector->push(new RouteEntry($fields));
    }

    /**
     * 析构期自动 register（fluent 链结束、变量出作用域时触发）。
     */
    public function __destruct()
    {
        if (!$this->registered) {
            $this->register();
        }
    }
}
