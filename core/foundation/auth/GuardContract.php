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
 * Guard 基础读取契约（所有端 guard 必须实现）。
 *
 * 语义边界：方法表达「**当前 guard 自身主体**」的身份事实，**不**做跨端代换。
 * - admin guard.id() = 当前管理员 ID
 * - front guard.id() = 当前会员 ID
 * - api guard.id()   = 当前 API 调用者会员 ID
 *
 * 调用面**禁止简写**。业务代码统一使用 `auth('admin'|'front'|'api')->method()`，
 * 以让「当前调的是哪端」永远显式可读。
 *
 * 未登录返回值约定：
 * - id()    → 0
 * - user()  → array()
 * - check() → false
 * - guest() → true
 *
 * 实例状态约定（DocBlock 级，运行时未强制校验）：
 * 实现类不应持有可变身份状态（避免长生命周期 runtime 跨请求泄漏），
 * 若需缓存请走「per-request 一次性 hydrate」语义。
 */
interface GuardContract
{
    /**
     * 当前 guard 主体 ID；无主体返回 0。
     *
     * @return int
     */
    public function id();

    /**
     * 当前 guard 主体资料行；无主体返回空数组。
     *
     * @return array
     */
    public function user();

    /**
     * 当前 guard 是否已认证。
     *
     * @return bool
     */
    public function check();

    /**
     * 当前 guard 是否未认证（!check）。
     *
     * @return bool
     */
    public function guest();
}
