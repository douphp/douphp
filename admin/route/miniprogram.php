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
 * miniprogram 后台声明式路由
 *
 * 声明 ?route=miniprogram[/<sub>]/<action> 形态：小程序主控制器（同步 / 发布 / 安装 / 启用 /
 * 删除）+ 导航 / 展示 / 系统三个子控制器。
 */

use Dou\Admin\Controller\Miniprogram\MiniprogramController;
use Dou\Admin\Controller\Miniprogram\NavController;
use Dou\Admin\Controller\Miniprogram\ShowController;
use Dou\Admin\Controller\Miniprogram\SystemController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::name('admin.')->compositeModule()->group(function () {
    Route::group('miniprogram', MiniprogramController::class)
        ->prefix('miniprogram')
        ->get(['index', 'release', 'install'])
        ->post(['sync', 'enable'])
        ->delete(['destroy']);

    Route::resource('miniprogram', NavController::class)
        ->prefix('miniprogram/nav')
        ->sub('nav');

    Route::resource('miniprogram', ShowController::class)
        ->prefix('miniprogram/show')
        ->sub('show')
        ->except(['create']);

    Route::group('miniprogram', SystemController::class)
        ->prefix('miniprogram/system')
        ->sub('system')
        ->get(['index'])
        ->post(['update']);
});
