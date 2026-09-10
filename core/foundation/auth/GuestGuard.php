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

namespace Dou\Core\Foundation\Auth;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 游客 Guard：会员模块未安装 / 未启用时由 front / api Init 兜底注册到 {@see AuthManager}。
 *
 * 为可选能力缺席时提供签名完整、行为无害的占位 Guard，避免 user 模块未装时
 * `auth('front')->id()` / `auth('api')->check()` 等调用抛 RuntimeException。
 *
 * 返回值严格按 {@see GuardContract} docblock 的「未登录约定」：
 * - id()    → 0
 * - user()  → array()
 * - check() → false
 * - guest() → true
 *
 * 故意 **不** 实现 {@see StatefulGuardContract}：登录 / 登出语义需要真实 user 模块；
 * 若调用面在 user 模块缺席时仍尝试 attempt/login/logout，更适合让上层路由 / 控制器
 * 在入口提示「请先安装会员模块」，而不是在 guard 内部静默吃掉。
 */
class GuestGuard implements GuardContract
{
    /**
     * {@inheritDoc}
     */
    public function id()
    {
        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function user()
    {
        return array();
    }

    /**
     * {@inheritDoc}
     */
    public function check()
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function guest()
    {
        return true;
    }
}
