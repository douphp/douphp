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
 * product 接口端声明式路由
 *
 * 声明 /api/?route=product[/...] 形态下的商品 API 映射：
 *   - 主控制器 ProductController：index（列表）/ show（product/{id}）/ attribute_list
 *   - 子控制器 WorkController（核销端，sub='work'）：index / add / edit / upload（保留 action-style）；store(POST product/work) / update(PUT product/work/{id}) / destroy(DELETE product/work/{id})
 */

use Dou\Api\Controller\Product\ProductController;
use Dou\Api\Controller\Product\WorkController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::name('api.')->group(function () {
    Route::resource('product', ProductController::class)
        ->only(array('index', 'show'))
        ->get(array('attribute_list'));

    Route::group('product', WorkController::class)
        ->prefix('product/work')
        ->sub('work')
        ->get(['index', 'add', 'edit'])
        ->post(['upload']);

    Route::resource('product', WorkController::class)
        ->prefix('product/work')
        ->sub('work')
        ->only(['store', 'update', 'destroy']);
});
