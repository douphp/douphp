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

use Dou\Core\Foundation\Container\Container;
use Dou\Core\Web\Routing\RouteEntry;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 中间件别名注册表与分层组装器
 *
 * 把「各端全局默认中间件栈（别名形态）」与「RouteEntry 携带的路由级细化」组装成最终
 * 可执行的中间件实例链，承载中间件分层模型的四种语义：
 *
 *   1. skipAllMiddleware  → 返回空链（最高优先级）
 *   2. withoutMiddleware  → 从默认栈过滤指定别名
 *   3. middleware（追加） → 默认栈末尾追加额外别名（支持 'alias:p1,p2' 参数 DSL）
 *   4. middlewareParams   → 给对应别名新建独占带参实例（不污染共享默认）
 *
 * 别名 → FQCN 映射由各端 Resolver 构造时传入（admin: auth/permission/csrf/workspace；
 * front/api: throttle/user_auth/csrf 等）。缺类（如 user 模块卸载导致 user_auth 类不存在）
 * 时吞掉并跳过该中间件（不挂、不 fatal）。
 *
 * 别名 DSL：`<alias>[:p1[,p2[,...]]]`，参数全为字符串，逗号分隔，禁嵌套。
 */
class MiddlewareRegistry
{
    /** @var Container */
    private $container;

    /** @var array<string,string> 别名 → 中间件 FQCN */
    private $aliasMap;

    /**
     * @param Container $container
     * @param array<string,string> $aliasMap 别名 → FQCN
     */
    public function __construct(Container $container, array $aliasMap)
    {
        $this->container = $container;
        $this->aliasMap = $aliasMap;
    }

    /**
     * 按默认别名栈 + 路由级细化组装最终中间件实例链。
     *
     * @param string[] $defaultAliases 该端全局默认栈（别名有序列表）
     * @param RouteEntry $entry 命中的路由条目（携带路由级中间件细化）
     * @return MiddlewareInterface[] 已实例化、已注参、按执行顺序排列
     */
    public function compose(array $defaultAliases, RouteEntry $entry)
    {
        return $this->composeFromSpec(
            $defaultAliases,
            (bool) $entry->skip_all_middleware,
            is_array($entry->without_middleware) ? $entry->without_middleware : array(),
            is_array($entry->middleware) ? $entry->middleware : array(),
            is_array($entry->middleware_params) ? $entry->middleware_params : array()
        );
    }

    /**
     * 按默认别名栈 + 散字段路由级细化组装最终中间件实例链。
     *
     * 与 {@see compose} 同语义，但不要求调用方持有 RouteEntry —— 供前台 PrettyRouteMatcher
     * 解析得到的数组形态结果直接复用同一套组装逻辑（避免在前台再造一份 compose）。
     *
     * @param string[] $defaultAliases 该端全局默认栈（别名有序列表）
     * @param bool $skipAll 跳过全部中间件（装空链）
     * @param string[] $without 路由级豁免别名
     * @param string[] $append 路由级追加别名（支持 'alias:p1,p2'）
     * @param array<string,string> $params 路由级别名参数覆盖（别名 => 参数串）
     * @return MiddlewareInterface[] 已实例化、已注参、按执行顺序排列
     */
    public function composeFromSpec(array $defaultAliases, $skipAll, array $without, array $append, array $params)
    {
        if ($skipAll) {
            return array();
        }

        $withoutMap = array();
        foreach ($without as $name) {
            $withoutMap[(string) $name] = true;
        }

        // 1) 默认栈（过滤豁免项），2) 末尾追加路由级 middleware；按别名去重保序。
        $specs = array();
        foreach ($defaultAliases as $alias) {
            $alias = (string) $alias;
            if ($alias !== '' && !isset($withoutMap[$alias])) {
                $specs[] = $alias;
            }
        }
        foreach ($append as $spec) {
            $spec = (string) $spec;
            if ($spec !== '') {
                $specs[] = $spec;
            }
        }

        $instances = array();
        $seen = array();
        foreach ($specs as $spec) {
            $parsed = self::parseSpec($spec);
            $alias = $parsed['alias'];
            if ($alias === '' || isset($seen[$alias])) {
                continue;
            }
            $seen[$alias] = true;

            // 参数优先级：fluent 糖 middlewareParams 覆盖 > 别名内联 DSL 参数
            $aliasParams = $parsed['params'];
            if (isset($params[$alias]) && (string) $params[$alias] !== '') {
                $aliasParams = self::splitParams((string) $params[$alias]);
            }

            $instance = $this->makeInstance($alias, $aliasParams);
            if ($instance !== null) {
                $instances[] = $instance;
            }
        }

        return $instances;
    }

    /**
     * 解析别名 + 参数 → 中间件实例；未注册别名 / 类缺失返回 null。
     *
     * 构造期异常（容器解析失败、依赖注入异常等）向上抛出，
     * 避免 CSRF 等安全中间件因构造异常被静默跳过、请求穿过管道不校验。
     *
     * @param string $alias
     * @param string[] $params
     * @return MiddlewareInterface|null
     */
    private function makeInstance($alias, array $params)
    {
        if (!isset($this->aliasMap[$alias])) {
            return null;
        }
        $fqcn = $this->aliasMap[$alias];
        if (!class_exists($fqcn)) {
            return null;
        }

        $instance = $this->container->make($fqcn);

        if (!empty($params) && $instance instanceof ParameterizedMiddleware) {
            $instance->setRouteParameters($params);
        }

        return $instance;
    }

    /**
     * 解析别名 DSL：`alias:p1,p2` → ['alias' => 'alias', 'params' => ['p1','p2']]。
     *
     * @param string $spec
     * @return array{alias:string,params:string[]}
     */
    public static function parseSpec($spec)
    {
        $spec = trim((string) $spec);
        $colon = strpos($spec, ':');
        if ($colon === false) {
            return array('alias' => $spec, 'params' => array());
        }
        $alias = trim(substr($spec, 0, $colon));
        $params = self::splitParams(substr($spec, $colon + 1));
        return array('alias' => $alias, 'params' => $params);
    }

    /**
     * 拆分逗号分隔参数串（去空白，保留空位以维持位置语义除尾部外）。
     *
     * @param string $raw
     * @return string[]
     */
    private static function splitParams($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return array();
        }
        $parts = explode(',', $raw);
        foreach ($parts as $i => $p) {
            $parts[$i] = trim($p);
        }
        return $parts;
    }
}
