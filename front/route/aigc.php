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
 * Release Date: 2026-11-11
 */

/**
 * aigc 模块前台声明式路由
 *
 * 应用中心（游客可浏览）：
 *   /aigc、/aigc/list
 * 会员端点：
 *   /aigc/app、/aigc/submit、/aigc/poll、/aigc/works、/aigc/file、
 *   /aigc/task_delete、/aigc/package、/aigc/stream、/aigc/new_session、
 *   /aigc/sessions、/aigc/messages
 * 会员中心：
 *   /user/aigc、/user/aigc/works、/user/aigc/history
 */

use Dou\Core\Web\Routing\Route;
use Dou\Front\Controller\Aigc\AigcController;
use Dou\Front\Controller\Aigc\ChatController;
use Dou\Front\Controller\Aigc\PackageController;
use Dou\Front\Controller\Aigc\UserController;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

if (!class_exists('Dou\\Core\\Service\\Ai\\AiGateway')) {
    return;
}

Route::get('aigc', AigcController::class, 'index', 'aigc', 'aigc');
Route::get('aigc/list', AigcController::class, 'listing', 'aigc', 'aigc.list');
Route::get('aigc/app', AigcController::class, 'app', 'aigc', 'aigc.app');
Route::post('aigc/submit', AigcController::class, 'submit', 'aigc', 'aigc.submit');
Route::get('aigc/poll', AigcController::class, 'poll', 'aigc', 'aigc.poll');
Route::get('aigc/works', AigcController::class, 'works', 'aigc', 'aigc.works');
Route::get('aigc/file', AigcController::class, 'file', 'aigc', 'aigc.file');
Route::post('aigc/task_delete', AigcController::class, 'task_delete', 'aigc', 'aigc.task_delete');
Route::get('aigc/package', PackageController::class, 'index', 'aigc', 'aigc.package');

Route::post('aigc/stream', ChatController::class, 'stream', 'aigc', 'aigc.stream');
Route::post('aigc/new_session', ChatController::class, 'new_session', 'aigc', 'aigc.new_session');
Route::get('aigc/sessions', ChatController::class, 'sessions', 'aigc', 'aigc.sessions');
Route::get('aigc/messages', ChatController::class, 'messages', 'aigc', 'aigc.messages');

Route::group('aigc', UserController::class)
    ->prefix('user/aigc')
    ->sub('user')
    ->get(['index', 'works', 'history']);
