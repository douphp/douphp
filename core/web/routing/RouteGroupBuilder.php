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
 * 声明式路由 fluent API 的链式 builder（一个 group = 同 module + 同 controller [+ 可选 sub] 的一组 actions）
 *
 * 由 {@see Route::group()} 创建并绑定到当前 {@see RouteCollector}，
 * 链式收集 prefix / sub / 可选 nameBase / 可选 rootAction，
 * 按动词方法 {@see get} / {@see post} / {@see put} / {@see patch} / {@see delete} / {@see any}
 * 把 actions 按 HTTP 方法分桶记录；析构时按声明顺序展开成 {@see RouteEntry[]} 推入 collector。
 *
 * 约定规则（与现有 front/route/*.php 字节等价）：
 *   - root action：默认 `index`（主组 / 子组一致）；可经 {@see root} 覆盖为任意动作
 *   - pattern：root → prefix；非 root → prefix . '/' . action
 *   - nameBase：默认主组 `module`、子组 `module . '.' . sub`；可经 {@see name} 覆盖
 *   - name：root 动作（含 index）抑制 action 后缀（如 `book.work` 而非 `book.work.index`，
 *           `admin.order` 而非 `admin.order.index`）；其他情形为 `nameBase . '.' . action`
 *
 * 动词方法选用：
 *   - 单纯 GET 页：`->get(['register', 'login', 'logout'])`
 *   - 单纯 POST 提交：`->post(['register_post', 'login_post'])`
 *   - PUT/PATCH/DELETE：对应方法；浏览器原生表单仍发 POST，由 Request::method() 的 _method
 *     伪装映射到对应动词
 *   - {@see any}：permissive 逃生口，须 code review 把关
 */
class RouteGroupBuilder
{
    use RouteMiddlewareDsl;
    use RouteNameResolution;

    /** @var RouteCollector */
    private $collector;

    /** @var string */
    private $module;

    /** @var string 已归一化为前导反斜杠的 FQCN */
    private $controller;

    /** @var string|null */
    private $prefix = null;

    /** @var string|null */
    private $sub = null;

    /** @var string|null rootAction 覆盖值；null 表示走默认推导 */
    private $rootActionOverride = null;

    /**
     * 已记录的动词分桶 actions。
     *
     * 数组按"动词方法被调用的先后顺序"追加；每项形如：
     *   ['methods' => ['POST'], 'actions' => ['login_post', 'register_post']]
     *
     * 析构期一次性展开成 RouteEntry，每个 action 拿到所在桶的 methods（如 POST 单动词、
     * PUT/PATCH 双动词由 put() 一次性带出、空数组 = permissive 由 any() 写入）。
     *
     * @var array<int, array{methods: string[], actions: string[]}>
     */
    private $buckets = array();

    /** @var bool 是否已 register（防止 __destruct 与显式调用重复展开） */
    private $registered = false;

    /**
     * @param RouteCollector $collector 当前路由文件的累积器
     * @param string $module 模块名（如 'health'）
     * @param string $controller 控制器 FQCN（前导反斜杠会自动归一化）
     */
    public function __construct(RouteCollector $collector, $module, $controller)
    {
        $this->collector = $collector;
        $this->module = (string) $module;
        $this->controller = '\\' . ltrim((string) $controller, '\\');
    }

    /**
     * 设置 group URL 前缀（不含尾斜杠），例 'user/health'。
     *
     * @param string $prefix
     * @return self
     */
    public function prefix($prefix)
    {
        $this->prefix = (string) $prefix;
        return $this;
    }

    /**
     * 设置子控制器段（{@see RouteEntry::$sub}），例 'work'。
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
     * 设置路由名。尾点（如 'api.'）= 前缀，叠加到默认 localBase；无尾点 = 全量覆盖。
     *
     * 不调用时默认值：主组 = module；子组 = module . '.' . sub；并叠加组栈前缀。
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
     * 仅影响 routeModule（喂后台权限 / 菜单 / 审计 / 校验），不影响路由名与 URL。
     *
     * @return self
     */
    public function compositeModule()
    {
        $this->composite = true;
        return $this;
    }

    /**
     * 覆盖 rootAction（映射到 group prefix 的 action 名）。
     *
     * 不调用时默认根动作为 'index'（主组 / 子组一致）。仅当根动作不是 index
     * （如 CartController 的 'cart_number'），或控制器无根动作（哨兵 '__noop__'）时才需显式调用。
     *
     * @param string $action
     * @return self
     */
    public function root($action)
    {
        $this->rootActionOverride = (string) $action;
        return $this;
    }

    /**
     * 追加一组 GET actions（methods = ['GET']）。
     *
     * @param string[] $actions
     * @return self
     */
    public function get(array $actions)
    {
        return $this->addBucket(array('GET'), $actions);
    }

    /**
     * 追加一组 POST actions（methods = ['POST']）。
     *
     * @param string[] $actions
     * @return self
     */
    public function post(array $actions)
    {
        return $this->addBucket(array('POST'), $actions);
    }

    /**
     * 追加一组 PUT actions（methods = ['PUT', 'PATCH']，与 RESTful update 对齐）。
     *
     * @param string[] $actions
     * @return self
     */
    public function put(array $actions)
    {
        return $this->addBucket(array('PUT', 'PATCH'), $actions);
    }

    /**
     * 追加一组 PATCH actions（methods = ['PATCH']）。
     *
     * @param string[] $actions
     * @return self
     */
    public function patch(array $actions)
    {
        return $this->addBucket(array('PATCH'), $actions);
    }

    /**
     * 追加一组 DELETE actions（methods = ['DELETE']）。
     *
     * @param string[] $actions
     * @return self
     */
    public function delete(array $actions)
    {
        return $this->addBucket(array('DELETE'), $actions);
    }

    /**
     * 追加一组 permissive actions（methods = []，任意 HTTP 方法命中）。
     *
     * 显式逃生口；须在代码评审中说明为何无法严格化。
     *
     * @param string[] $actions
     * @return self
     */
    public function any(array $actions)
    {
        return $this->addBucket(array(), $actions);
    }

    /**
     * 析构期自动 register（fluent 链结束、变量出作用域时触发）。
     */
    public function __destruct()
    {
        if (!$this->registered) {
            $this->flush();
        }
    }

    /**
     * 内部：把动词桶追加进 buckets 队列，过滤空 / 非字符串项。
     *
     * @param string[] $methods
     * @param string[] $actions
     * @return self
     */
    private function addBucket(array $methods, array $actions)
    {
        $normalized = array();
        foreach ($actions as $a) {
            $a = (string) $a;
            if ($a !== '') {
                $normalized[] = $a;
            }
        }
        if (!empty($normalized)) {
            $this->buckets[] = array('methods' => $methods, 'actions' => $normalized);
        }
        return $this;
    }

    /**
     * 把已记录的动词分桶展开成 RouteEntry[] 推入 collector。幂等。
     *
     * 展开顺序：按 buckets 数组顺序（即"动词方法被调用的先后顺序"），每个桶内
     * 再按 actions 数组顺序展开；与 PrettyRouteMatcher 的 specificity + 方法消歧
     * 兼容（同 prefix 不同动词共存靠 acceptsMethod()，声明顺序不影响命中正确性）。
     *
     * @return void
     */
    private function flush()
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;

        if ($this->prefix === null) {
            throw new \LogicException('Route group missing prefix(): module=' . $this->module);
        }

        $rootAction = $this->rootActionOverride !== null
            ? $this->rootActionOverride
            : 'index';

        $defaultLocalBase = $this->sub === null ? $this->module : ($this->module . '.' . $this->sub);
        $nameBase = $this->resolveDeclaredName($this->collector, $defaultLocalBase);

        $emittedModule = $this->resolveEmittedModule($this->collector, $this->module, $this->sub);

        $mwFields = $this->middlewareFields();

        foreach ($this->buckets as $bucket) {
            $methods = $bucket['methods'];
            foreach ($bucket['actions'] as $action) {
                $isRoot = ($action === $rootAction);

                $pattern = $isRoot ? $this->prefix : ($this->prefix . '/' . $action);

                $name = $this->actionTakesBaseName($action, $rootAction)
                    ? $nameBase
                    : ($nameBase . '.' . $action);

                $fields = array(
                    'name' => $name,
                    'route_type' => 'declared',
                    'pattern' => $pattern,
                    'params' => array(),
                    'module' => $emittedModule,
                    'controller' => $this->controller,
                    'action' => $action,
                    'sub' => $this->sub,
                    'methods' => $methods,
                    'is_short_url_aware' => false,
                    'is_family' => false,
                    'source' => $this->collector->sourceFor($name),
                );
                $fields = array_merge($fields, $mwFields);

                $this->collector->push(new RouteEntry($fields));
            }
        }
    }
}
