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
 * 声明式路由 fluent API 的 resource builder（CRUD 标准动作集批量展开）
 *
 * 由 {@see Route::resource()} 创建并绑定到当前 {@see RouteCollector}，链式 prefix / sub /
 * only / except，以及动词方法 {@see get} / {@see post} / {@see put} / {@see patch} /
 * {@see delete} / {@see any} 追加 extras，构造结束时（__destruct 或显式 register）按
 * 「URL = prefix/<动作>」展开成 {@see RouteEntry[]} 推入 collector。
 *
 * 默认标准动作集（不调 only() 时）：index / create / store / edit / update / destroy。show 不在默认集内，需经
 * `->only([...,'show'])` 显式开启（避免给未实现 show() 方法的资源静默新增 {id} GET 路由）。
 *
 * 展开规则（RESTful 资源路径化，见 {@see self::$resourceMap}）：
 *   - index   GET    pattern = prefix
 *   - create  GET    pattern = prefix/create
 *   - store   POST   pattern = prefix
 *   - show    GET    pattern = prefix/{id}      （仅 only() 显式开启）
 *   - edit    GET    pattern = prefix/{id}/edit
 *   - update  PUT,PATCH pattern = prefix/{id}
 *   - destroy DELETE pattern = prefix/{id}
 *   - extra（自定义动作）按调用动词方法时的 methods 写入；pattern = prefix/<action>，id 走 query
 *   - name：index → nameBase；其余 → nameBase.action
 *   - prefix 默认为 module；nameBase 默认为 module（主组）/ module.sub（子控制器组）
 *
 * 与 {@see RouteGroupBuilder} 的关系：group 提供逐 action 显式枚举（适合非 CRUD 控制器与前台
 * 会员中心），resource 提供标准 CRUD 集 + only / except + 动词方法 extras；两者最终都 push
 * `route_type='declared'` 的 RouteEntry。
 */
class RouteResourceBuilder
{
    use RouteMiddlewareDsl;
    use RouteNameResolution;

    /** @var string[] 标准 CRUD 动作集 */
    private static $defaultActions = array('index', 'create', 'store', 'edit', 'update', 'destroy');

    /**
     * 资源动作 → URL 形态 + HTTP 方法白名单映射（RESTful 资源路径化）。
     *
     * suffix 追加在 prefix 之后构成 pattern；needs_id=true 的动作在 pattern 内嵌
     * `{id}` 占位符（约束 [0-9]+），消歧 member 级动作（update/destroy 同 `prefix/{id}`，
     * 靠 HTTP 方法区分）。extra 动作（不在本表）按调用动词方法时的 methods 展开。
     *
     * @var array<string, array{suffix:string, methods:string[], needs_id:bool}>
     */
    private static $resourceMap = array(
        'index' => array('suffix' => '', 'methods' => array('GET'), 'needs_id' => false),
        'create' => array('suffix' => '/create', 'methods' => array('GET'), 'needs_id' => false),
        'store' => array('suffix' => '', 'methods' => array('POST'), 'needs_id' => false),
        'show' => array('suffix' => '/{id}', 'methods' => array('GET'), 'needs_id' => true),
        'edit' => array('suffix' => '/{id}/edit', 'methods' => array('GET'), 'needs_id' => true),
        'update' => array('suffix' => '/{id}', 'methods' => array('PUT', 'PATCH'), 'needs_id' => true),
        'destroy' => array('suffix' => '/{id}', 'methods' => array('DELETE'), 'needs_id' => true),
    );

    /** @var RouteCollector */
    private $collector;

    /** @var string */
    private $module;

    /** @var string 已归一化前导反斜杠的 FQCN */
    private $controller;

    /** @var string */
    private $prefix;

    /** @var string|null */
    private $sub = null;

    /** @var string 成员动作（edit/update/destroy）路径参数名（默认 'id'） */
    private $keyName = 'id';

    /** @var string 成员动作路径参数的 PCRE 约束（默认数字主键） */
    private $keyPattern = '[0-9]+';

    /** @var string[]|null only 列表（null 表示不过滤） */
    private $onlyList = null;

    /** @var string[] except 列表 */
    private $exceptList = array();

    /**
     * Extras 动词分桶（按动词方法被调用的先后顺序追加）。
     *
     * @var array<int, array{methods: string[], actions: string[]}>
     */
    private $extraBuckets = array();

    /** @var bool 是否已 register（防止 __destruct 与显式调用重复展开） */
    private $registered = false;

    /**
     * @param RouteCollector $collector 当前路由文件的累积器
     * @param string $module 模块名（如 'article' / 'article_category'）
     * @param string $controller 控制器 FQCN（建议用 ::class）
     */
    public function __construct(RouteCollector $collector, $module, $controller)
    {
        $this->collector = $collector;
        $this->module = (string) $module;
        $this->controller = '\\' . ltrim((string) $controller, '\\');
        $this->prefix = $this->module;
    }

    /**
     * 设置 URL 前缀（覆盖默认的 module）。
     *
     * 适用于子控制器形态（如 article_category 模块、prefix='article/category'）。
     *
     * @param string $prefix 不含尾斜杠
     * @return self
     */
    public function prefix($prefix)
    {
        $this->prefix = (string) $prefix;
        return $this;
    }

    /**
     * 设置子控制器段（{@see RouteEntry::$sub}）。
     *
     * 子控制器形态时 nameBase 默认变为 module.sub；可与 prefix 配合：
     *   Route::resource('article_category', CategoryController::class)
     *       ->prefix('article/category')
     *       ->sub('category');
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
     * 设置路由名。尾点（如 'api.'）= 前缀，叠加到默认 localBase；无尾点（如 'api.book'）= 全量覆盖。
     * 不调用时走默认推导（module 或 module.sub），并叠加组栈前缀。
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
     * 覆盖成员动作（edit/update/destroy）的路径参数名与约束。
     *
     * 控制器以领域主键名（如 category_id / work_id / vote_id / user_id）从请求取成员标识、
     * 且该名直接是数据库列名时，用本方法把资源路径占位符从默认 `{id}` 改为 `{<name>}`，
     * 这样匹配器按具名捕获注入到请求输入袋的就是控制器期望的键，控制器 / FormRequest /
     * Service 无需改动。pattern 默认沿用数字主键约束；非数字主键（如 slug）显式传第二参。
     *
     * @param string $name 路径参数名（同时是控制器读取的请求键）
     * @param string $pattern 该参数的 PCRE 片段约束（默认 '[0-9]+'）
     * @return self
     */
    public function key($name, $pattern = '[0-9]+')
    {
        $this->keyName = (string) $name;
        $this->keyPattern = (string) $pattern;
        return $this;
    }

    /**
     * 仅保留指定标准 CRUD 动作（候选宇宙为 self::$resourceMap 全部已知动作，含 show）。
     *
     * 仅影响标准集；extras 由动词方法显式追加，不受 only/except 控制。
     * 唯一能开启 show 动作（GET prefix/{id}）的入口：`->only([...,'show'])`。
     *
     * @param string[] $actions
     * @return self
     */
    public function only(array $actions)
    {
        $this->onlyList = $actions;
        return $this;
    }

    /**
     * 排除指定标准 CRUD 动作（在 only 之后生效）。
     *
     * @param string[] $actions
     * @return self
     */
    public function except(array $actions)
    {
        foreach ($actions as $a) {
            $this->exceptList[] = (string) $a;
        }
        return $this;
    }

    /**
     * 追加一组 GET extras（不在标准 CRUD 集中的读动作）。
     *
     * @param string[] $actions
     * @return self
     */
    public function get(array $actions)
    {
        return $this->addExtraBucket(array('GET'), $actions);
    }

    /**
     * 追加一组 POST extras（不在标准 CRUD 集中的写动作）。
     *
     * @param string[] $actions
     * @return self
     */
    public function post(array $actions)
    {
        return $this->addExtraBucket(array('POST'), $actions);
    }

    /**
     * 追加一组 PUT extras（methods = ['PUT', 'PATCH']）。
     *
     * @param string[] $actions
     * @return self
     */
    public function put(array $actions)
    {
        return $this->addExtraBucket(array('PUT', 'PATCH'), $actions);
    }

    /**
     * 追加一组 PATCH extras（methods = ['PATCH']）。
     *
     * @param string[] $actions
     * @return self
     */
    public function patch(array $actions)
    {
        return $this->addExtraBucket(array('PATCH'), $actions);
    }

    /**
     * 追加一组 DELETE extras（methods = ['DELETE']）。
     *
     * @param string[] $actions
     * @return self
     */
    public function delete(array $actions)
    {
        return $this->addExtraBucket(array('DELETE'), $actions);
    }

    /**
     * 追加一组 permissive extras（methods = []，任意 HTTP 方法命中）。
     *
     * 显式逃生口；须在代码评审中说明为何无法严格化。
     *
     * @param string[] $actions
     * @return self
     */
    public function any(array $actions)
    {
        return $this->addExtraBucket(array(), $actions);
    }

    /**
     * 显式注册（按当前 prefix / sub / only / except / extras 展开成 RouteEntry 推入 collector）。
     *
     * 通常无需显式调用：__destruct 会在 builder 引用消失时自动 register。
     * 显式 register 后再 __destruct 不会重复展开。
     *
     * @return void
     */
    public function register()
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;

        $standardActions = $this->resolveStandardActions();
        $extraActions = $this->resolveExtraActions();
        if (empty($standardActions) && empty($extraActions)) {
            return;
        }

        $defaultLocalBase = $this->sub === null ? $this->module : ($this->module . '.' . $this->sub);
        $nameBase = $this->resolveDeclaredName($this->collector, $defaultLocalBase);

        $mwFields = $this->middlewareFields();

        foreach ($standardActions as $action) {
            $spec = self::$resourceMap[$action];
            $methods = $spec['methods'];
            if ($spec['needs_id']) {
                $suffix = str_replace('{id}', '{' . $this->keyName . '}', $spec['suffix']);
                $params = array($this->keyName => $this->keyPattern);
            } else {
                $suffix = $spec['suffix'];
                $params = array();
            }
            $pattern = $this->prefix . $suffix;

            $this->pushEntry($action, $pattern, $methods, $params, $nameBase, $mwFields);
        }

        foreach ($extraActions as $entry) {
            $pattern = $this->prefix . '/' . $entry['action'];
            $this->pushEntry($entry['action'], $pattern, $entry['methods'], array(), $nameBase, $mwFields);
        }
    }

    /**
     * 析构期自动 register（fluent 链结束、变量出作用域时触发）。
     *
     * 与 register() 幂等：显式调用后再析构不会重复展开。
     */
    public function __destruct()
    {
        if (!$this->registered) {
            $this->register();
        }
    }

    /**
     * 内部：把 extras 动词桶追加进 extraBuckets，过滤空 / 非字符串项。
     *
     * @param string[] $methods
     * @param string[] $actions
     * @return self
     */
    private function addExtraBucket(array $methods, array $actions)
    {
        $normalized = array();
        foreach ($actions as $a) {
            $a = (string) $a;
            if ($a !== '') {
                $normalized[] = $a;
            }
        }
        if (!empty($normalized)) {
            $this->extraBuckets[] = array('methods' => $methods, 'actions' => $normalized);
        }
        return $this;
    }

    /**
     * 推入单条 RouteEntry。
     *
     * @param string $action
     * @param string $pattern
     * @param string[] $methods
     * @param array $params
     * @param string $nameBase
     * @param array $mwFields
     * @return void
     */
    private function pushEntry($action, $pattern, array $methods, array $params, $nameBase, array $mwFields)
    {
        $name = $this->actionTakesBaseName($action) ? $nameBase : ($nameBase . '.' . $action);

        $fields = array(
            'name' => $name,
            'route_type' => 'declared',
            'pattern' => $pattern,
            'params' => $params,
            'methods' => $methods,
            'module' => $this->resolveEmittedModule($this->collector, $this->module, $this->sub),
            'controller' => $this->controller,
            'action' => $action,
            'sub' => $this->sub,
            'is_short_url_aware' => false,
            'is_family' => false,
            'source' => $this->collector->sourceFor($name),
        );
        $fields = array_merge($fields, $mwFields);

        $this->collector->push(new RouteEntry($fields));
    }

    /**
     * 解析当前配置下的标准 CRUD 动作列表（去重保序，应用 only / except 过滤）。
     *
     * 无 only() 时基准为 {@see self::$defaultActions}（6 项，不含 show）——保证既有
     * `Route::resource(...)` 调用面行为不变，不给未实现 show() 的资源静默新增路由。
     * 调用 only() 时基准切换为 {@see self::$resourceMap} 全部已知动作（含 show），按
     * resourceMap 声明序过滤；这样 `->only(['index','show'])` 才能显式开启 show 动作。
     *
     * @return string[]
     */
    private function resolveStandardActions()
    {
        if ($this->onlyList !== null) {
            $keep = $this->onlyList;
            $merged = array();
            foreach (array_keys(self::$resourceMap) as $a) {
                if (in_array($a, $keep, true)) {
                    $merged[] = $a;
                }
            }
        } else {
            $merged = self::$defaultActions;
        }

        if (!empty($this->exceptList)) {
            $skip = $this->exceptList;
            $filtered = array();
            foreach ($merged as $a) {
                if (!in_array($a, $skip, true)) {
                    $filtered[] = $a;
                }
            }
            $merged = $filtered;
        }

        return $merged;
    }

    /**
     * 解析当前配置下的 extras 动作列表（按动词桶顺序展开，每项带 methods）。
     *
     * 应用 except 过滤：extras 也会被 except 移除。
     * extras 之间按声明顺序去重：同名 action 仅保留首次出现。
     *
     * @return array<int, array{action: string, methods: string[]}>
     */
    private function resolveExtraActions()
    {
        $result = array();
        $seen = array();
        foreach ($this->extraBuckets as $bucket) {
            foreach ($bucket['actions'] as $action) {
                if (isset($seen[$action])) {
                    continue;
                }
                if (in_array($action, $this->exceptList, true)) {
                    continue;
                }
                if (in_array($action, self::$defaultActions, true)) {
                    // 与默认 CRUD 集同名的 extras 视为冲突，跳过（默认集由 resourceMap 主导动词）。
                    // show 不在默认集，允许 `->get(['show'])` 以字面段 prefix/show 形态声明
                    // （前台会员中心 aftersale 等用法）；标准 {id} 形态的 show 经 only() 开启。
                    continue;
                }
                $seen[$action] = true;
                $result[] = array('action' => $action, 'methods' => $bucket['methods']);
            }
        }
        return $result;
    }
}
