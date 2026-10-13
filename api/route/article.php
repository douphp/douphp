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
 * article 接口端声明式路由
 *
 * 声明 /api/?route=article[/{id}] 形态下的文章 API 映射：
 *   - ArticleController：index（列表）/ show（id 走 {id} 路径段）
 *
 * 追加开放平台发布端点（open/v1/*，仅 open 模块已装时注册）：
 *   - ArticleOpenController：store / update / destroy，签名准入由 open_auth 中间件按
 *     scope（article.publish / article.update / article.delete）裁决。
 *   守卫必须是「模块开关 + 中间件类存在」双条件：MiddlewareRegistry 对缺失中间件
 *   静默跳过（fail-open），仅靠别名挂载会在 open 卸载后裸奔，故声明层 fail-closed。
 */

use Dou\Api\Controller\Article\ArticleController;
use Dou\Api\Controller\Article\ArticleOpenController;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Foundation\Middleware\OpenAuthMiddleware;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::resource('article', ArticleController::class)
    ->name('api.')
    ->only(array('index', 'show'));

if (Module::has('open') && class_exists(OpenAuthMiddleware::class)) {
    Route::name('api.')->group(function () {
        Route::post('open/v1/article', ArticleOpenController::class, 'store', 'open')
            ->middleware('open_auth:article.publish');
        Route::put('open/v1/article/{id}', ArticleOpenController::class, 'update', 'open')
            ->params(array('id' => '[0-9]+'))
            ->middleware('open_auth:article.update');
        Route::delete('open/v1/article/{id}', ArticleOpenController::class, 'destroy', 'open')
            ->params(array('id' => '[0-9]+'))
            ->middleware('open_auth:article.delete');
    });
}
