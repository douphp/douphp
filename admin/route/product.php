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
 * product 后台声明式路由
 *
 * 声明 ?route=product[/<action>] 与 ?route=product/category[/<action>] 形态下的后台动作映射：
 *   - ProductController：标准 CRUD + 额外动作 thumb / model / action
 *   - 子控制器 CategoryController（sub='category'）：标准 CRUD
 */

use Dou\Admin\Controller\Product\CategoryController;
use Dou\Admin\Controller\Product\ProductController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::name('admin.')->compositeModule()->group(function () {
    Route::resource('product', ProductController::class)
        ->post(['thumb', 'model', 'action']);

    Route::resource('product', CategoryController::class)
        ->prefix('product/category')
        ->sub('category');
});
