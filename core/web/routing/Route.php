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
 * 声明式路由 fluent API 的静态门面（仅 build-time，仅供 front/route/*.php 等声明文件使用）
 *
 * 本门面仅在 manifest 构建期使用（由 RouteManifestBuilder::buildDeclared include 路由声明文件时），
 * 不参与 per-request 上下文（无 request / auth / session 等）。
 *
 * 工作流：RouteManifestBuilder 在 include 每个 route 文件前调 {@see useCollector}
 * 安装一个 {@see RouteCollector}，include 期间路由文件链式调用 fluent API，由 builder
 * 把累积的 RouteEntry append 到 manifest。
 *
 * 暴露入口（按粒度由细到粗）：
 *   - {@see get} / {@see post} / {@see put} / {@see patch} / {@see delete} / {@see match} /
 *     {@see any} —— 单条任意 pattern → 控制器/动作映射，按 HTTP 方法分动词入口
 *   - {@see group} —— 同模块同控制器的多 action 显式枚举（动词方法 ->get/post/...）
 *   - {@see resource} —— admin / api 标准 CRUD 集 + only/except + 动词方法 extras
 *   - {@see column} / {@see simple} / {@see page} —— 按当前选中风格规则展开 declared 条目
 */
class Route
{
    /** @var RouteCollector|null 当前 build-time 的累积器，仅 RouteManifestBuilder 写入 */
    private static $collector = null;

    /**
     * 安装当前路由文件的累积器（builder 内部调用）。
     *
     * 传 null 表示卸载（建议在 include 完成后调用以避免门面状态外泄）。
     *
     * @param RouteCollector|null $collector
     * @return void
     */
    public static function useCollector($collector = null)
    {
        self::$collector = $collector;
    }

    /**
     * 返回当前累积器，无安装时抛 LogicException（fail-fast）。
     *
     * @return RouteCollector
     */
    public static function collector()
    {
        if (self::$collector === null) {
            throw new \LogicException('Route facade has no collector installed; only callable inside RouteManifestBuilder include cycle.');
        }
        return self::$collector;
    }

    /**
     * 开启一个组级 name 前缀注册器（Laravel 风格 `Route::name('api.')->group(...)`）。
     *
     * 返回 {@see RouteGroupRegistrar}，链式 ->compositeModule() 可选，终结调用 ->group(callable)
     * 把组属性入栈、执行回调、出栈。组内 resource/group/单 verb 的默认 localBase 自动叠加该前缀。
     *
     * @param string $prefix name 前缀（尾点表示前缀，如 'api.' / 'admin.'）
     * @return RouteGroupRegistrar
     */
    public static function name($prefix)
    {
        return (new RouteGroupRegistrar(self::collector()))->name($prefix);
    }

    /**
     * 开启一个组级 compositeModule 注册器（组内有 ->sub() 的路由 routeModule 取复合名）。
     *
     * 多用于后台子资源保持复合 routeModule 名（如 article_category）。可与 ->name() 链式叠加。
     *
     * @return RouteGroupRegistrar
     */
    public static function compositeModule()
    {
        return (new RouteGroupRegistrar(self::collector()))->compositeModule();
    }

    /**
     * 开启一个声明 group：module + controller 组合。
     *
     * 后续链式 ->prefix(...) 必填、->sub(...) / ->name(...) / ->root(...) 选填；
     * 终结调用动词方法 ->get([...]) / ->post([...]) / ->put([...]) / ->patch([...]) /
     * ->delete([...]) 把每个 action 按动词展开成一条 declared RouteEntry；
     * ->any([...]) 为 permissive 逃生口（methods=[]），仅在确需不区分 HTTP 方法时使用。
     *
     * @param string $module 模块名（如 'health'）
     * @param string $controller 控制器 FQCN（建议用 ::class，前导反斜杠会自动归一化）
     * @return RouteGroupBuilder
     */
    public static function group($module, $controller)
    {
        return new RouteGroupBuilder(self::collector(), $module, $controller);
    }

    /**
     * 开启一个声明 resource：CRUD 标准动作集批量展开。
     *
     * 默认展开 {index(GET), create(GET), store(POST), edit(GET), update(PUT/PATCH),
     * destroy(DELETE)} 六条 entry；可经链式 ->prefix() / ->sub() / ->only([...]) /
     * ->except([...]) 调整。Extras 经动词方法 ->get([...]) / ->post([...]) /
     * ->put([...]) / ->patch([...]) / ->delete([...]) 追加；->any([...]) 为 permissive
     * 逃生口（methods=[]），仅在确需不区分 HTTP 方法时使用。
     * 析构时自动 register；无需显式终结调用。
     *
     * @param string $module 模块名（如 'article'、子控制器形态 'article_category'）
     * @param string $controller 控制器 FQCN
     * @return RouteResourceBuilder
     */
    public static function resource($module, $controller)
    {
        return new RouteResourceBuilder(self::collector(), $module, $controller);
    }

    /**
     * 登记一条 GET 路由（methods = ['GET']，最细粒度入口）。
     *
     * 适用于非 CRUD 控制器的逐条声明（如 admin 的 setting / tool / cloud / module；
     * 前台扁平动词 URL；page 模块的多形态等）。
     *
     * 语义严格：仅接受 GET（HEAD 在 acceptsMethod 内按 GET 处理）。需要 POST / PUT /
     * PATCH / DELETE 等其它动词请改用 {@see post} / {@see put} / {@see patch} /
     * {@see delete} / {@see match}；仅当确实要"任意动词命中同一控制器"才用 {@see any}。
     *
     * 返回 {@see RouteEntryBuilder} 以支持链式 ->name()/->params()/->sub()/->middleware()
     * 等；旧式位置参数调用（无链式）也兼容：builder 在出作用域 __destruct 时自动 register。
     *
     * @param string $pattern URL pattern（PrettyUrlCompiler 迷你语言）
     * @param string $controller 控制器 FQCN
     * @param string $action 控制器动作名（路径段形态，由 MethodResolver 二次解析至 PHP 方法名）
     * @param string $module 路由模块名（写入 RouteEntry::$module）
     * @param string|null $name 路由名（点分），null 时由 $module + $action 自动派生
     * @param array $params 占位符正则映射（可空）
     * @param string|null $sub 子控制器段（可空）
     * @return RouteEntryBuilder
     */
    public static function get($pattern, $controller, $action, $module, $name = null, array $params = array(), $sub = null)
    {
        return self::makeEntry(array('GET'), $pattern, $controller, $action, $module, $name, $params, $sub);
    }

    /**
     * 登记一条 POST 路由（methods = ['POST']）。
     *
     * @param string $pattern
     * @param string $controller
     * @param string $action
     * @param string $module
     * @param string|null $name
     * @param array $params
     * @param string|null $sub
     * @return RouteEntryBuilder
     */
    public static function post($pattern, $controller, $action, $module, $name = null, array $params = array(), $sub = null)
    {
        return self::makeEntry(array('POST'), $pattern, $controller, $action, $module, $name, $params, $sub);
    }

    /**
     * 登记一条 PUT 路由（methods = ['PUT','PATCH']，与 RESTful update 对齐）。
     *
     * @param string $pattern
     * @param string $controller
     * @param string $action
     * @param string $module
     * @param string|null $name
     * @param array $params
     * @param string|null $sub
     * @return RouteEntryBuilder
     */
    public static function put($pattern, $controller, $action, $module, $name = null, array $params = array(), $sub = null)
    {
        return self::makeEntry(array('PUT', 'PATCH'), $pattern, $controller, $action, $module, $name, $params, $sub);
    }

    /**
     * 登记一条 PATCH 路由（methods = ['PATCH']）。
     *
     * @param string $pattern
     * @param string $controller
     * @param string $action
     * @param string $module
     * @param string|null $name
     * @param array $params
     * @param string|null $sub
     * @return RouteEntryBuilder
     */
    public static function patch($pattern, $controller, $action, $module, $name = null, array $params = array(), $sub = null)
    {
        return self::makeEntry(array('PATCH'), $pattern, $controller, $action, $module, $name, $params, $sub);
    }

    /**
     * 登记一条 DELETE 路由（methods = ['DELETE']）。
     *
     * @param string $pattern
     * @param string $controller
     * @param string $action
     * @param string $module
     * @param string|null $name
     * @param array $params
     * @param string|null $sub
     * @return RouteEntryBuilder
     */
    public static function delete($pattern, $controller, $action, $module, $name = null, array $params = array(), $sub = null)
    {
        return self::makeEntry(array('DELETE'), $pattern, $controller, $action, $module, $name, $params, $sub);
    }

    /**
     * 登记一条多方法路由（显式指定 methods 白名单）。
     *
     * @param string[] $methods HTTP 方法白名单（如 ['GET','POST']）
     * @param string $pattern
     * @param string $controller
     * @param string $action
     * @param string $module
     * @param string|null $name
     * @param array $params
     * @param string|null $sub
     * @return RouteEntryBuilder
     */
    public static function match(array $methods, $pattern, $controller, $action, $module, $name = null, array $params = array(), $sub = null)
    {
        return self::makeEntry($methods, $pattern, $controller, $action, $module, $name, $params, $sub);
    }

    /**
     * 登记一条 permissive 路由（任意 HTTP 方法命中）。
     *
     * permissive 显式逃生口：仅当某个 URL 在产品上确实需要同时承载多动词（如某外部
     * webhook 同 URL 双方法、或尚无法按动词拆分的遗留端点），才用 `any()`。
     * 凡是 `Route::any()` 出现的地方都须在代码评审中说明为何无法严格化。
     * 日常的「已知 GET / POST 路径」请改用具体动词方法。
     *
     * @param string $pattern
     * @param string $controller
     * @param string $action
     * @param string $module
     * @param string|null $name
     * @param array $params
     * @param string|null $sub
     * @return RouteEntryBuilder
     */
    public static function any($pattern, $controller, $action, $module, $name = null, array $params = array(), $sub = null)
    {
        return self::makeEntry(array(), $pattern, $controller, $action, $module, $name, $params, $sub);
    }

    /**
     * 内部：构造单条路由 builder（统一各 verb 入口）。
     *
     * @param string[] $methods
     * @param string $pattern
     * @param string $controller
     * @param string $action
     * @param string $module
     * @param string|null $name
     * @param array $params
     * @param string|null $sub
     * @return RouteEntryBuilder
     */
    private static function makeEntry(array $methods, $pattern, $controller, $action, $module, $name, array $params, $sub)
    {
        $builder = new RouteEntryBuilder(self::collector(), $methods, $pattern, $controller, $action, $module, $name);
        if (!empty($params)) {
            $builder->params($params);
        }
        if ($sub !== null && $sub !== '') {
            $builder->sub($sub);
        }
        return $builder;
    }

    /**
     * 按当前选中 column 风格规则展开为指定模块的 declared 条目。
     *
     * 每条 column 规则展开为一条 entry（{module} 占位符替换为 $module）；规则携带 target →
     * action='index'，规则无 target → action='show'。控制器固定为 $controller，不走约定式
     * 发现。风格切换在每次 manifest 构建期生效。
     *
     * @param string $module 栏目模块名（如 'product'）
     * @param string $controller 该栏目模块对应的控制器 FQCN
     * @return void
     */
    public static function column($module, $controller)
    {
        $collector = self::collector();
        $sourcePrefix = 'declared:' . $collector->relPath();
        foreach (StyleRuleExpander::expandColumn($module, $controller, $sourcePrefix) as $entry) {
            $collector->push($entry);
        }
    }

    /**
     * 按当前选中 simple 风格规则展开为指定模块的 declared 条目。
     *
     * 与 {@see column} 同结构；适用于单表型模块（如 brand / store / team）的公开 URL。
     *
     * @param string $module 单表模块名
     * @param string $controller 控制器 FQCN
     * @return void
     */
    public static function simple($module, $controller)
    {
        $collector = self::collector();
        $sourcePrefix = 'declared:' . $collector->relPath();
        foreach (StyleRuleExpander::expandSimple($module, $controller, $sourcePrefix) as $entry) {
            $collector->push($entry);
        }
    }

    /**
     * 按当前选中 page 风格规则展开 page 模块的 declared 条目。
     *
     * page 规则一律 module_fixed='page'，pattern 含 {slug} / {id} 等具体占位符（无 {module}）；
     * action 固定 'show'。
     *
     * @param string $controller PageController FQCN
     * @return void
     */
    public static function page($controller)
    {
        $collector = self::collector();
        $sourcePrefix = 'declared:' . $collector->relPath();
        foreach (StyleRuleExpander::expandPage($controller, $sourcePrefix) as $entry) {
            $collector->push($entry);
        }
    }
}
