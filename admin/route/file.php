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
 * file 后台声明式路由
 *
 * 声明 ?route=file/<action> 形态：附件管理。box（展示位文件管理）/ delete / bigfile，无 index 根。
 */

use Dou\Admin\Controller\File\FileController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::group('file', FileController::class)->name('admin.')
    ->prefix('file')
    ->root('__noop__')
    ->post(['box', 'crop', 'cropPref', 'destroy', 'bigfile']);
