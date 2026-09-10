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

/**
 * login 后台声明式路由
 *
 * 声明 ?route=login[/<action>] 形态：后台登录 / 登出 / 找回密码全流程。
 *
 * 全组豁免 auth/permission/workspace：登录前无 session，挂这三个中间件会把登录页本身
 * 重定向回登录页造成死循环。`login/post`（账号密码提交）额外豁免 csrf —— 登录前共享令牌
 * static_admin 尚未下发，匿名提交无令牌，故单独拆出为带 csrf 豁免的 POST 路由；
 * `password_reset_post` 保留 csrf（走一次性令牌 password_reset，由 CsrfMiddleware::tokenIdFor 解析）。
 */

use Dou\Admin\Controller\Login\LoginController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::name('admin.')->group(function () {
    Route::group('login', LoginController::class)
        ->prefix('login')
        ->withoutMiddleware(['auth', 'permission', 'workspace'])
        ->get(['index', 'password_reset'])
        ->post(['logout', 'password_reset_post']);

    Route::post('login/post', LoginController::class, 'post', 'login')
        ->withoutMiddleware(['auth', 'permission', 'workspace', 'csrf']);
});
