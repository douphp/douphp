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

namespace Dou\Admin\Middleware;

use Dou\Core\Foundation\Middleware\MiddlewareInterface;
use Dou\Core\Web\Http\HttpResponseException;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台认证中间件
 *
 * 鉴权单一职责：经 `auth('admin')->restoreFromSession()` 恢复登录态；未登录抛
 * HttpResponseException 跳登录页，登录态注入完成后放行管道。
 *
 * 免登入口（登录页 / 验证码图片）由路由级 `->withoutMiddleware(['auth', ...])` 声明式豁免，
 * 不在本中间件内硬编码模块名单。
 *
 * 视图层全局变量（global_admin / workspace / unum）由 {@see AdminWorkspaceMiddleware} 承载；
 * 模块准入由 {@see PermissionMiddleware} 承载。
 */
class AuthMiddleware implements MiddlewareInterface
{
    /**
     * @param callable $next
     * @return mixed
     */
    public function handle($next)
    {
        $adminInfo = auth('admin')->restoreFromSession((string) request()->ip());
        if (!$adminInfo) {
            throw new HttpResponseException(redirect(route('admin.login')));
        }

        return $next();
    }
}
