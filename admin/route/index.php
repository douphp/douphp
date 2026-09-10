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
 * index 后台声明式路由
 *
 * 首页 route('admin.index') 与 API 端一致，pattern 为 index；开启伪静态出站 /admin/index，
 * 关闭时为 index.php?route=index。入站 ?route= 为空时仍由 BackendDeclaredMatcher 回落 index。
 * POST 子动作为 index/clear_cache、index/close_quick_start、index/delete_install。
 */

use Dou\Admin\Controller\Index\IndexController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::name('admin.')->group(function () {
    Route::get('index', IndexController::class, 'index', 'index');

    Route::group('index', IndexController::class)
        ->prefix('index')
        ->post(['clear_cache', 'close_quick_start', 'delete_install']);
});
