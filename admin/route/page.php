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
 * page 后台声明式路由
 *
 * 声明 ?route=page[/<action>] 形态：单页 CRUD + 可视化编辑 visualize / 清空编辑 visualize_clear。
 * 名前缀使用 'admin.page' 以避免与前台 page meta 规则冲突（前台 page 用 module_fixed='page' 路由）。
 */

use Dou\Admin\Controller\Page\PageController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::resource('page', PageController::class)
    ->name('admin.')
    ->post(['visualize', 'visualize_clear']);
