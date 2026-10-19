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
 * 路由清单条目（值对象，规范化构造与字段访问）
 *
 * 字段契约见 docs/adr/2026-06-14-route-manifest-contract.md §2.1。
 * 实例字段全部 public 只读约定（PHP 5.6 不强制 readonly，靠规则与代码评审）；
 * 业务代码须经构造函数创建，禁止动态写入字段。
 *
 * 入站清单实体的 route_type ∈ {'home','declared'}；出站规则视图另使用 page / column /
 * column_short / simple。
 * - meta 模板条目（page/column/column_short/simple）来自 config/route.php，pattern 含 {module} 等占位符；
 *   仅存在于 UrlBuilder 的出站规则视图，不参与入站匹配。
 * - 具体条目（declared）pattern 已无元变量，供具名反查（如 user_center.member）使用。
 *
 * 实际清单（getEntries）仅出现 home / declared；page / column / simple 等 meta 模板
 * 只存在于 getRuleGroups() 的出站视图，不进入入站清单。
 */
class RouteEntry
{
    /** @var string|null 路由唯一名称（点分），如 'book.user'；null 表示风格派生匿名条目 */
    public $name;

    /** @var string route_type 见类头注释 */
    public $route_type;

    /** @var string URL 模板（PrettyUrlCompiler 迷你语言）；空串仅 home 条目允许 */
    public $pattern;

    /** @var array 占位符默认正则，pattern 内联正则优先 */
    public $params;

    /** @var string|null 目标模板 */
    public $target;

    /** @var string|null 固定模块名（仅 page 用） */
    public $module_fixed;

    /** @var string|null 风格派生展开后的具体模块名；meta 模板条目为 null */
    public $module;

    /**
     * @var string|null 父模块名（声明首参的干净值）。
     *
     * 仅在 composite 拼接前保留的原始模块名，用于后台权限的「子资源透明继承父模块」判定；
     * 无 sub 时与 {@see $module} 相等。落盘缓存序列化以承载该关系。
     */
    public $parent_module;

    /** @var string|null 控制器 FQCN，构建期解析 */
    public $controller;

    /** @var string|null 动作段 */
    public $action;

    /** @var string|null 子段（子控制器路由用） */
    public $sub;

    /** @var array 中间件元数据 */
    public $middleware;

    /**
     * @var array HTTP 方法白名单（如 ['GET']、['POST']、['PUT','PATCH']）。
     *            空数组表示 permissive（任意方法命中，保 front declared 现状零改动）。
     */
    public $methods;

    /** @var array 路由级豁免中间件别名（从默认栈中过滤，如 ['auth','permission']） */
    public $without_middleware;

    /** @var array 路由级中间件参数覆盖（如 ['user_auth' => 'required','throttle' => '5,60']） */
    public $middleware_params;

    /** @var bool 路由级跳过全部中间件（空链；首页 / llms.txt 等字面豁免） */
    public $skip_all_middleware;

    /** @var string 诊断元数据：来源标识符 */
    public $source;

    /**
     * 构造路由条目。
     *
     * @param array $fields 字段映射（缺失字段填默认）
     */
    public function __construct(array $fields)
    {
        $this->name = isset($fields['name']) ? $fields['name'] : null;
        $this->route_type = isset($fields['route_type']) ? (string) $fields['route_type'] : 'declared';
        $this->pattern = isset($fields['pattern']) ? (string) $fields['pattern'] : '';
        $this->params = isset($fields['params']) && is_array($fields['params']) ? $fields['params'] : array();
        $this->target = isset($fields['target']) ? $fields['target'] : null;
        $this->module_fixed = isset($fields['module_fixed']) ? $fields['module_fixed'] : null;
        $this->module = isset($fields['module']) ? $fields['module'] : null;
        $this->parent_module = isset($fields['parent_module']) ? $fields['parent_module'] : null;
        $this->controller = isset($fields['controller']) ? $fields['controller'] : null;
        $this->action = isset($fields['action']) ? $fields['action'] : null;
        $this->sub = isset($fields['sub']) ? $fields['sub'] : null;
        $this->middleware = isset($fields['middleware']) && is_array($fields['middleware']) ? $fields['middleware'] : array();
        $this->methods = isset($fields['methods']) && is_array($fields['methods']) ? self::normalizeMethods($fields['methods']) : array();
        $this->without_middleware = isset($fields['without_middleware']) && is_array($fields['without_middleware']) ? $fields['without_middleware'] : array();
        $this->middleware_params = isset($fields['middleware_params']) && is_array($fields['middleware_params']) ? $fields['middleware_params'] : array();
        $this->skip_all_middleware = !empty($fields['skip_all_middleware']);
        $this->source = isset($fields['source']) ? (string) $fields['source'] : '';
    }

    /**
     * 推导 declared 条目所属端（依 controller FQCN 命名空间前缀）。
     *
     * 用于 PrettyRouteMatcher / route-audit 等跨端隔离场景：admin / api 的 declared
     * 条目不应被前台匹配器或前台 self-hit 测试当作前台 URL 解析。
     *
     * @return string|null 'Front' | 'Admin' | 'Api' | null（无 controller 或非声明式条目）
     */
    public function endNamespace()
    {
        if ($this->controller === null || $this->controller === '') {
            return null;
        }
        $ctrl = (string) $this->controller;
        if (strpos($ctrl, '\\Dou\\Front\\') === 0 || strpos($ctrl, 'Dou\\Front\\') === 0) {
            return 'Front';
        }
        if (strpos($ctrl, '\\Dou\\Admin\\') === 0 || strpos($ctrl, 'Dou\\Admin\\') === 0) {
            return 'Admin';
        }
        if (strpos($ctrl, '\\Dou\\Api\\') === 0 || strpos($ctrl, 'Dou\\Api\\') === 0) {
            return 'Api';
        }
        return null;
    }

    /**
     * 还原为 config/route.php 风格的 rule 字段子集（UrlBuilder 与 PrettyRouteMatcher 共用）。
     *
     * 仅保留 pattern / params / target / module_fixed 四个规则字段；匹配器需要的方法与
     * 显式目标字段由 PrettyRouteMatcher::loadRoutePatterns() 单独补齐。
     *
     * @return array
     */
    public function toRuleArray()
    {
        $r = array('pattern' => $this->pattern);
        if (!empty($this->params)) {
            $r['params'] = $this->params;
        }
        if ($this->target !== null) {
            $r['target'] = $this->target;
        }
        if ($this->module_fixed !== null) {
            $r['module_fixed'] = $this->module_fixed;
        }
        return $r;
    }

    /**
     * 全字段数组形态（诊断 / 缓存序列化用）。
     *
     * @return array
     */
    public function toArray()
    {
        return array(
            'name' => $this->name,
            'route_type' => $this->route_type,
            'pattern' => $this->pattern,
            'params' => $this->params,
            'target' => $this->target,
            'module_fixed' => $this->module_fixed,
            'module' => $this->module,
            'parent_module' => $this->parent_module,
            'controller' => $this->controller,
            'action' => $this->action,
            'sub' => $this->sub,
            'middleware' => $this->middleware,
            'methods' => $this->methods,
            'without_middleware' => $this->without_middleware,
            'middleware_params' => $this->middleware_params,
            'skip_all_middleware' => $this->skip_all_middleware,
            'source' => $this->source,
        );
    }

    /**
     * 当前条目是否接受指定 HTTP 方法。
     *
     * 空 methods = permissive（任意方法命中）；HEAD 始终按 GET 处理。
     *
     * @param string $httpMethod 大写 HTTP 方法
     * @return bool
     */
    public function acceptsMethod($httpMethod)
    {
        if (empty($this->methods)) {
            return true;
        }
        $httpMethod = strtoupper((string) $httpMethod);
        if ($httpMethod === 'HEAD') {
            $httpMethod = 'GET';
        }
        return in_array($httpMethod, $this->methods, true);
    }

    /**
     * 归一化 methods 列表（大写、去重、去空）。
     *
     * @param array $methods
     * @return array
     */
    private static function normalizeMethods(array $methods)
    {
        $result = array();
        foreach ($methods as $m) {
            $m = strtoupper(trim((string) $m));
            if ($m !== '' && !in_array($m, $result, true)) {
                $result[] = $m;
            }
        }
        return $result;
    }
}
