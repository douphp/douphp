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
 */

use Dou\Api\Controller\Article\ArticleController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::resource('article', ArticleController::class)
    ->name('api.')
    ->only(array('index', 'show'));
