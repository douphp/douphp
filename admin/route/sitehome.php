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
 * site_home 后台声明式路由
 *
 * 声明 ?route=site_home 形态：网站首页可视化编辑入口。模块名按 admin/init/route.php 与
 * SiteHomeController 一致取 'site_home'（含下划线）。
 */

use Dou\Admin\Controller\SiteHome\SiteHomeController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::group('site_home', SiteHomeController::class)->name('admin.')
    ->prefix('site_home')
    ->get(['index'])
    ->post(['sync']);
