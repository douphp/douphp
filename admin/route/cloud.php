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
 * cloud 后台声明式路由
 *
 * 声明 ?route=cloud[/<action>] 形态：豆壳云服务（安装 / 订单 / 账号 / 版权）。
 */

use Dou\Admin\Controller\Cloud\CloudController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::name('admin.')->group(function () {
    Route::group('cloud', CloudController::class)
        ->prefix('cloud')
        ->get(['index', 'install', 'account', 'update'])
        ->post(['details', 'order', 'account_post', 'copyright', 'account_clean']);

    // install_step：安装单步 JSON API，控制器自带 csrf()->verify() 并返回 JSON 419，
    // 不能走 CsrfMiddleware 的 HTML 提示页拒绝（会破坏 API 契约），故路由级豁免 csrf。
    Route::any('cloud/install_step', CloudController::class, 'install_step', 'cloud')
        ->withoutMiddleware(['csrf']);
});
