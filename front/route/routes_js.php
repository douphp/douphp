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
 * routes_js 命名路由 manifest 脚本端点声明式路由
 *
 * 公开 GET：/routes_js（伪静态）或 index.php?route=routes_js。输出 window.__douRouteManifest，
 * 供 route.js 在前台各页消费。内容与语言无关，按内容指纹 immutable 缓存。
 */

use Dou\Core\Web\Routing\Route;
use Dou\Front\Controller\Asset\RoutesController;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::get('routes_js', RoutesController::class, 'manifest', 'routes_js', 'routes_js');
