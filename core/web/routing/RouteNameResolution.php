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
 * 声明式路由「名前缀 + compositeModule」解析 DSL（被三个 builder 复用）
 *
 * 承载两类语义，均可来自单条链式调用或组属性栈（{@see RouteGroupRegistrar} 经
 * {@see RouteCollector} 入栈）：
 *
 *   - name 前缀（尾点判定）：`->name('api.')`（尾点）= 前缀，叠加到默认 localBase；
 *     `->name('api.book')`（无尾点）= 全量覆盖 localBase。组栈前缀与单条前缀按
 *     `qualifyName()` 幂等拼接，禁止 `api.api.book`。
 *   - compositeModule：仅影响 emit 的 `module` 字段（喂后台权限 / 菜单 / 审计 / 校验），
 *     不影响路由名与 URL；仅在 `sub` 非空时生效。组栈开启或单条开启任一为真即生效。
 */
trait RouteNameResolution
{
    /** @var string|null 单条 name() 入参；null 走默认推导 */
    protected $nameArg = null;

    /** @var bool 单条 compositeModule() 标记 */
    protected $composite = false;

    /**
     * 解析最终路由名（叠加组栈前缀 + 单条 name 语义 + 默认 localBase）。
     *
     * @param RouteCollector $collector 当前累积器（提供组栈前缀）
     * @param string $defaultLocalBase 默认 localBase（resource/group 为 module 或 module.sub）
     * @return string
     */
    protected function resolveDeclaredName(RouteCollector $collector, $defaultLocalBase)
    {
        $stackPrefix = (string) $collector->currentNamePrefix();
        $arg = $this->nameArg;

        if ($arg !== null && $arg !== '') {
            if (substr($arg, -1) === '.') {
                return self::qualifyName($stackPrefix . $arg, $defaultLocalBase);
            }
            return self::qualifyName($stackPrefix, $arg);
        }

        return self::qualifyName($stackPrefix, $defaultLocalBase);
    }

    /**
     * 解析 emit 的 module 字段：compositeModule 开启且 sub 非空时取复合名 module_sub。
     *
     * @param RouteCollector $collector 当前累积器（提供组栈 composite 标记）
     * @param string $cleanModule 干净父 module
     * @param string|null $sub 子段
     * @return string
     */
    protected function resolveEmittedModule(RouteCollector $collector, $cleanModule, $sub)
    {
        $composite = $this->composite || $collector->currentComposite();
        if ($composite && $sub !== null && $sub !== '') {
            return $cleanModule . '_' . $sub;
        }
        return $cleanModule;
    }

    /**
     * 判定某 action 是否取「裸 nameBase」（不追加 .action 后缀）。
     *
     * 三类 builder（group / resource / entry）共用同一根动作语义：
     *   - action 等于该声明的根动作（group 默认 index，可经 ->root() 覆盖为任意动作）；
     *   - 或 action 为约定的 index（与 RESTful「index = 资源根」一致，resource / entry 据此判定）。
     * 两者任一成立即取裸 nameBase；其余动作一律 nameBase.action。
     *
     * @param string $action 当前动作名
     * @param string $rootAction 该声明的根动作（resource / entry 恒为 index）
     * @return bool
     */
    protected function actionTakesBaseName($action, $rootAction = 'index')
    {
        return $action === $rootAction || $action === 'index';
    }

    /**
     * 幂等拼接前缀与基名：base 已以 prefix 开头则不再叠加。
     *
     * @param string $prefix 前缀（可空，通常以 '.' 结尾）
     * @param string $base 基名
     * @return string
     */
    protected static function qualifyName($prefix, $base)
    {
        $prefix = (string) $prefix;
        $base = (string) $base;
        if ($prefix === '') {
            return $base;
        }
        if ($base === '') {
            return rtrim($prefix, '.');
        }
        if (strpos($base, $prefix) === 0) {
            return $base;
        }
        return $prefix . $base;
    }
}
