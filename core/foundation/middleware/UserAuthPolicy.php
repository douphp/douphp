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
 * 前台/小程序会员鉴权策略表（纯解析器）
 *
 * 仅服务 front 与 api 两端的 UserAuthMiddleware；admin 端有独立鉴权模型（session + 权限轴），
 * 不引用本类。命名带 `User` 锁死服务对象，避免被无意识扩成 all-purpose policy。
 *
 * 吃路由段与配置表，吐 mode + workRequired 决策；不调 request() / auth()，所有输入显式
 * 入参（符合 core/utility/service 不直接读 Request 与 Auth 的硬规则）。PHP 5.6 安全。
 *
 *   $decision = UserAuthPolicy::resolve($module, $action, $sub, $parent, $authModes, $workRequired);
 *   // $decision === array('mode' => 'public'|'optional'|'required', 'workRequired' => true|false)
 */
class UserAuthPolicy
{
    /**
     * 默认鉴权模式：未在 auth_modes 中显式声明的路由按 optional 处理
     * （尝试解析登录态并按需注入身份缓存，但解析失败不拦截）。
     */
    const DEFAULT_MODE = 'optional';

    /**
     * 解析当前路由的鉴权策略。
     *
     * @param string $module 当前模块（routeModule）
     * @param string $action 当前动作（routeAction，空时调用方应传 'default'）
     * @param string $sub 当前子段（routeSub），无则空串
     * @param string $parent 模块名含下划线时的父段，无则空串
     * @param array $authModes 配置表：候选键 => 'public'|'optional'|'required'
     * @param array $workRequired 配置表：候选键列表，命中表示该路由需 work 身份
     * @return array array('mode' => 'public'|'optional'|'required', 'workRequired' => bool)
     */
    public static function resolve($module, $action, $sub, $parent, array $authModes, array $workRequired)
    {
        $module = strtolower(trim((string) $module));
        $action = strtolower(trim((string) $action));
        $sub = strtolower(trim((string) $sub));
        $parent = strtolower(trim((string) $parent));

        $candidates = self::buildCandidates($module, $action, $sub, $parent);

        $mode = self::DEFAULT_MODE;
        foreach ($candidates as $candidate) {
            if (!isset($authModes[$candidate])) {
                continue;
            }
            $value = strtolower(trim((string) $authModes[$candidate]));
            if ($value === 'public' || $value === 'optional' || $value === 'required') {
                $mode = $value;
                break;
            }
        }

        $workRequiredFlag = false;
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $workRequired, true)) {
                $workRequiredFlag = true;
                break;
            }
        }

        return array('mode' => $mode, 'workRequired' => $workRequiredFlag);
    }

    /**
     * 构造候选键列表（精确 > 父段 > 模块根，保证「细命中优先于粗命中」）。
     *
     * 当前仅 UserAuthPolicy 内部消费；不抽公开 RouteCandidateBuilder（YAGNI：
     * 真出现第二个消费者再抽不迟）。
     *
     * @param string $module
     * @param string $action
     * @param string $sub
     * @param string $parent
     * @return array
     */
    private static function buildCandidates($module, $action, $sub, $parent)
    {
        $candidates = array();
        if ($module !== '' && $sub !== '' && $action !== '') {
            $candidates[] = $module . '/' . $sub . '/' . $action;
        }
        if ($module !== '' && $sub !== '') {
            $candidates[] = $module . '/' . $sub;
        }
        if ($module !== '' && $action !== '') {
            $candidates[] = $module . '/' . $action;
        }
        if ($parent !== '' && $sub !== '' && $action !== '') {
            $candidates[] = $parent . '/' . $sub . '/' . $action;
        }
        if ($parent !== '' && $sub !== '') {
            $candidates[] = $parent . '/' . $sub;
        }
        if ($module !== '') {
            $candidates[] = $module;
        }
        if ($parent !== '') {
            $candidates[] = $parent;
        }

        return array_values(array_unique($candidates));
    }
}
