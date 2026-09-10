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
 * 会话型 Guard 契约（admin / front 实现；API 端为参数态鉴权，**不**实现本契约）。
 *
 * 在 {@see GuardContract} 之上追加凭据登录尝试 / 直接登录 / 登出三类
 * 主体状态写入方法。落地实现持有「写 session / 写 token / 更新登录痕迹」
 * 等副作用，业务调用面通过 `auth('admin')->attempt(...)` 等显式入口触发。
 *
 * 编排逻辑（验证码 / IP 限流 / event 触发 / 跳转）**不**放在 guard 自身，
 * 由对应端的「编排服务」（如 admin AdminLoginFlow / front UserLoginFlow）
 * 负责串接，guard 仅承担「凭据校验 + 主体状态写入」核心动作。
 */
interface StatefulGuardContract extends GuardContract
{
    /**
     * 凭据登录尝试：校验凭据 → 命中则写入主体登录态。
     *
     * 凭据字段集由端自身约定（admin: username/password；front: account/password 等）。
     *
     * @param array $credentials
     * @param bool $remember 是否同时发放 remember-me 续登凭证
     * @return bool 校验是否通过（true 时 guard 已切到已登录态）
     */
    public function attempt(array $credentials, $remember = false);

    /**
     * 直接以已知主体行登录（注册后立刻登录 / 三方登录回调 / 后台代登场景）。
     *
     * 调用方负责保证 $user 是合法的主体行（至少含主键字段），
     * guard 仅做「写 session / 写 token / 更新登录痕迹」副作用。
     *
     * @param array $user 主体行
     * @param bool $remember
     * @return void
     */
    public function login(array $user, $remember = false);

    /**
     * 登出当前主体：清 session / 清 remember 凭证 / 重置实例状态。
     *
     * @return void
     */
    public function logout();
}
