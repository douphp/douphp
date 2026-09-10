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
 * manager 后台声明式路由
 *
 * 声明 ?route=manager[/<action>] 形态：后台管理员账号 CRUD + 操作日志查看 log。
 */

use Dou\Admin\Controller\Manager\ManagerController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::resource('manager', ManagerController::class)->name('admin.')
    ->get(['log']);
