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

use Dou\Core\Web\Routing\PrettyUrlCompiler;
use Dou\Core\Web\Routing\RouteManifest;
use Dou\Core\Web\Routing\ShortUrlPolicy;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台外观 URL 规则匹配器（纯声明式）
 *
 * 入站匹配仅依据 RouteManifest 中前台命名空间的 declared 条目，含两类来源：
 *   - 系统内置端点（llms.txt / sitemap.xml / captcha / search / plugin / index 等）由
 *     {@see \Dou\Core\Web\Routing\RouteManifestBuilder::buildSystemDeclared} 明文表声明；
 *   - 业务端点由 front/route/*.php 经 Route::column / simple / page / group / get 声明，
 *     其中 column / simple / page 已由 {@see \Dou\Core\Web\Routing\StyleRuleExpander}
 *     按当前选中风格展开为具体 pattern。
 *
 * 规则未命中即视为非法路由（由 FrontResolver 派发为 page_wrong）。pattern 正则编译统一交给
 * {@see PrettyUrlCompiler}（与 UrlBuilder 出站填充共用同一引擎，保证双向可逆）。
 *
 * 唯一保留的硬编码出口是首页空串：matched 返回 IndexController 短路，避免空 pattern 参与
 * 通用正则匹配的歧义。
 *
 * 短地址（ShortUrlPolicy）：启用后该模块 URL 省略模块名段；解析前拦截带前缀长格式，未命中规则时
 * 补回模块前缀再用同一套 declared 规则重试，保证「省略前缀短链」与「完整声明 pattern」可逆。
 */
class PrettyRouteMatcher
{
    /** @var array 预编译路由规则（仅前台 declared 条目，含 _regex / _explicit_*） */
    private $routePatterns;

    public function __construct()
    {
        $this->routePatterns = $this->loadRoutePatterns();
    }

    /**
     * 解析外观 URL 为标准路由信息。
     *
     * @param string $route 已去语言前缀的路由字符串
     * @param string|null $httpMethod 当前 HTTP 方法（含方法伪装）；null = permissive 不做方法过滤
     * @return array {matched, name, module, target, controller, is_detail, route_type, action, sub, params}
     */
    public function normalize($route, $httpMethod = null)
    {
        $route = trim((string) $route, '/');

        $blank = array(
            'matched' => false,
            'name' => '',
            'module' => '',
            'target' => '',
            'controller' => null,
            'is_detail' => false,
            'route_type' => '',
            'action' => 'index',
            'sub' => '',
            'params' => array(),
            'mw_append' => array(),
            'mw_without' => array(),
            'mw_params' => array(),
            'mw_skip_all' => false,
        );

        if ($route === '') {
            return array_merge($blank, array(
                'matched' => true,
                'module' => 'index',
                'target' => 'index',
                'controller' => '\\Dou\\Front\\Controller\\Index\\IndexController',
            ));
        }

        // 短地址模块启用后，禁止再使用「模块名/…」长格式（与 UrlBuilder 生成规则一致，只认省略前缀的 URL）
        if (ShortUrlPolicy::isPrefixedRoute($route)) {
            return $blank;
        }

        $match = $this->matchRoutePatterns($route, $httpMethod);
        if ($match !== null) {
            return $this->buildResult($match['rule'], $match['captured']);
        }

        // 短地址兜底：原路径未命中任何规则时（如 1932.html / fenleiyi-blog 纯单段），
        // 补回 module 前缀再用同一套规则解析，保证「省略前缀的短链」与「完整规则模板」可逆。
        if (ShortUrlPolicy::enabled()) {
            $prefixed = ShortUrlPolicy::prefixRoute($route);
            if ($prefixed !== '') {
                $match = $this->matchRoutePatterns($prefixed, $httpMethod);
                if ($match !== null) {
                    return $this->buildResult($match['rule'], $match['captured']);
                }
            }
        }

        return $blank;
    }

    /**
     * 匹配路径到 declared 规则（资源路径化后 URL 含 `{id}` 占位符，需消歧）。
     *
     * 与 {@see \Dou\Core\Web\Routing\BackendDeclaredMatcher} 同款算法：
     *   1. 收集**全部** URL 命中规则；无命中 → null。
     *   2. specificity 排序：占位符少者优先 → 字面段多者优先 → 声明顺序兜底
     *      （保证 `order/cart` 胜过 `order/{id}`、`user/contact/{id}/edit` 与 `user/contact/{id}`
     *      靠后续方法过滤消歧）。
     *   3. 传入 $httpMethod：按排序遍历，首个方法命中即返回；URL 命中但方法全不命中 → null
     *      （前台无 405 页，按未命中交 FrontResolver 派发 page_wrong）。
     *   4. 未传 $httpMethod（permissive，如 route-list 诊断）：返回排序后首条。
     *
     * @param string $path
     * @param string|null $httpMethod
     * @return array|null {rule, captured}；无命中返回 null
     */
    private function matchRoutePatterns($path, $httpMethod = null)
    {
        $candidates = array();
        $order = 0;
        foreach ($this->routePatterns as $rule) {
            if (!preg_match($rule['_regex'], $path, $matches)) {
                $order++;
                continue;
            }
            $pattern = isset($rule['pattern']) ? (string) $rule['pattern'] : '';
            $candidates[] = array(
                'rule' => $rule,
                'captured' => $this->extractAllCaptures($matches),
                'placeholders' => self::placeholderCount($pattern),
                'literals' => self::literalSegmentCount($pattern),
                'order' => $order,
            );
            $order++;
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, array(__CLASS__, 'compareSpecificity'));

        if ($httpMethod === null) {
            return array('rule' => $candidates[0]['rule'], 'captured' => $candidates[0]['captured']);
        }

        foreach ($candidates as $candidate) {
            if (self::ruleAcceptsMethod($candidate['rule'], $httpMethod)) {
                return array('rule' => $candidate['rule'], 'captured' => $candidate['captured']);
            }
        }

        return null;
    }

    /**
     * 规则是否接受指定 HTTP 方法（空 methods = permissive；HEAD 按 GET 处理）。
     *
     * @param array $rule
     * @param string $httpMethod
     * @return bool
     */
    private static function ruleAcceptsMethod(array $rule, $httpMethod)
    {
        $methods = isset($rule['methods']) && is_array($rule['methods']) ? $rule['methods'] : array();
        if (empty($methods)) {
            return true;
        }
        $httpMethod = strtoupper((string) $httpMethod);
        if ($httpMethod === 'HEAD') {
            $httpMethod = 'GET';
        }
        return in_array($httpMethod, $methods, true);
    }

    /**
     * specificity 比较器：占位符少者优先 → 字面段多者优先 → 声明顺序兜底。
     *
     * @param array $a
     * @param array $b
     * @return int
     */
    private static function compareSpecificity($a, $b)
    {
        if ($a['placeholders'] !== $b['placeholders']) {
            return ($a['placeholders'] < $b['placeholders']) ? -1 : 1;
        }
        if ($a['literals'] !== $b['literals']) {
            return ($a['literals'] > $b['literals']) ? -1 : 1;
        }
        if ($a['order'] === $b['order']) {
            return 0;
        }
        return ($a['order'] < $b['order']) ? -1 : 1;
    }

    /**
     * pattern 内占位符 `{...}` 数量。
     *
     * @param string $pattern
     * @return int
     */
    private static function placeholderCount($pattern)
    {
        return preg_match_all('/\{[^}]+\}/', (string) $pattern, $m);
    }

    /**
     * pattern 内不含占位符的字面段数量（'/'-分段）。
     *
     * @param string $pattern
     * @return int
     */
    private static function literalSegmentCount($pattern)
    {
        $pattern = trim((string) $pattern, '/');
        if ($pattern === '') {
            return 0;
        }
        $count = 0;
        foreach (explode('/', $pattern) as $seg) {
            if ($seg !== '' && strpos($seg, '{') === false) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 把命中的 declared 规则与捕获组组装为标准路由信息。
     *
     * 模块 / 动作 / 目标 / 子段 / 控制器一律取 entry 显式字段（_explicit_*）；具名捕获组
     * （除 module / action / sub_action）作为路由参数带出。
     *
     * @param array $rule 命中的规则
     * @param array $captured 具名捕获组
     * @return array
     */
    private function buildResult(array $rule, array $captured)
    {
        $skipKeys = array('module' => 1, 'action' => 1, 'sub_action' => 1);
        $params = array();
        foreach ($captured as $k => $v) {
            if (!isset($skipKeys[$k]) && $v !== '') {
                $params[$k] = $v;
            }
        }

        $declModule = isset($rule['_explicit_module']) ? (string) $rule['_explicit_module'] : '';
        $declTarget = isset($rule['_explicit_target']) && $rule['_explicit_target'] !== ''
            ? (string) $rule['_explicit_target']
            : $declModule;
        $declAction = isset($rule['_explicit_action']) && $rule['_explicit_action'] !== null
            ? (string) $rule['_explicit_action']
            : 'index';
        $declSub = isset($rule['_explicit_sub']) && $rule['_explicit_sub'] !== null
            ? (string) $rule['_explicit_sub']
            : '';
        $declController = isset($rule['_explicit_controller']) ? $rule['_explicit_controller'] : null;
        $declName = isset($rule['_explicit_name']) ? (string) $rule['_explicit_name'] : '';

        return array(
            'matched' => true,
            'name' => $declName,
            'module' => $declModule,
            'target' => $declTarget,
            'controller' => $declController,
            'is_detail' => false,
            'route_type' => 'declared',
            'action' => $declAction,
            'sub' => $declSub,
            'params' => $params,
            'mw_append' => isset($rule['_explicit_mw_append']) && is_array($rule['_explicit_mw_append']) ? $rule['_explicit_mw_append'] : array(),
            'mw_without' => isset($rule['_explicit_mw_without']) && is_array($rule['_explicit_mw_without']) ? $rule['_explicit_mw_without'] : array(),
            'mw_params' => isset($rule['_explicit_mw_params']) && is_array($rule['_explicit_mw_params']) ? $rule['_explicit_mw_params'] : array(),
            'mw_skip_all' => isset($rule['_explicit_mw_skip_all']) ? (bool) $rule['_explicit_mw_skip_all'] : false,
        );
    }

    /**
     * 仅保留具名且非空的捕获组。
     *
     * @param array $matches preg_match 结果
     * @return array
     */
    private function extractAllCaptures($matches)
    {
        $captured = array();
        foreach ($matches as $k => $v) {
            if (is_string($k) && $v !== '') {
                $captured[$k] = $v;
            }
        }
        return $captured;
    }

    /**
     * 从 RouteManifest 加载入站匹配器使用的扁平规则集（仅前台 declared 条目）。
     *
     * declared 端隔离：仅前台命名空间（controller 落在 \Dou\Front\）的 declared 条目进入前台规则表；
     * admin / api 的 declared 条目通过各自 Resolver 消费，不参与前台 PrettyUrl 解析（即便 pattern
     * 字面相同，URL 语义不同）。
     *
     * 每条附加 `_explicit_*` 字段，让 buildResult 直接读 entry 显式 controller / module / action /
     * target / sub。
     *
     * @return array
     */
    private function loadRoutePatterns()
    {
        $config = array();
        foreach (RouteManifest::getEntriesByType('declared') as $entry) {
            $end = $entry->endNamespace();
            if ($end !== null && $end !== 'Front') {
                continue;
            }
            $rule = $entry->toRuleArray();
            $rule['_regex'] = PrettyUrlCompiler::compileToRegex(
                $rule['pattern'],
                isset($rule['params']) ? $rule['params'] : array()
            );
            // toRuleArray() 不带 methods；方法感知消歧（update PUT vs destroy DELETE 共用
            // prefix/{id}）依赖 ruleAcceptsMethod() 读取该字段，须显式从 entry 带出。
            $rule['methods'] = is_array($entry->methods) ? $entry->methods : array();
            $rule['_explicit_name'] = $entry->name;
            $rule['_explicit_module'] = $entry->module;
            $rule['_explicit_action'] = $entry->action;
            $rule['_explicit_target'] = $entry->module;
            $rule['_explicit_sub'] = $entry->sub;
            $rule['_explicit_controller'] = $entry->controller;
            $rule['_explicit_mw_append'] = is_array($entry->middleware) ? $entry->middleware : array();
            $rule['_explicit_mw_without'] = is_array($entry->without_middleware) ? $entry->without_middleware : array();
            $rule['_explicit_mw_params'] = is_array($entry->middleware_params) ? $entry->middleware_params : array();
            $rule['_explicit_mw_skip_all'] = (bool) $entry->skip_all_middleware;
            $config[] = $rule;
        }
        return $config;
    }
}
