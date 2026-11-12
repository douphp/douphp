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
 * aigc 接口端声明式路由
 *
 * 声明 /api/?route=aigc[/...] 形态下的会员 AI 应用 API：
 *   - 应用中心 / 应用详情 / 生成提交与轮询 / 作品 / 文件 / 套餐
 *   - 文本对话：流式输出、会话与消息
 *   - 会员侧（sub='user'）：user/aigc 配额与订阅
 */

use Dou\Api\Controller\Aigc\AigcController;
use Dou\Api\Controller\Aigc\ChatController;
use Dou\Api\Controller\Aigc\PackageController;
use Dou\Api\Controller\Aigc\UserController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

// aigc 运行时硬依赖 ai 模块下发的 core/service/ai 类；缺失时不注册路由，避免容器反射致命错误
if (!class_exists('Dou\\Core\\Service\\Ai\\AiGateway')) {
    return;
}

Route::name('api.')->group(function () {
    Route::get('aigc', AigcController::class, 'index', 'aigc');
    Route::get('aigc/list', AigcController::class, 'list', 'aigc');
    Route::get('aigc/app', AigcController::class, 'app', 'aigc');
    Route::post('aigc/submit', AigcController::class, 'submit', 'aigc');
    Route::get('aigc/poll', AigcController::class, 'poll', 'aigc');
    Route::get('aigc/works', AigcController::class, 'works', 'aigc');
    Route::get('aigc/file', AigcController::class, 'file', 'aigc');
    Route::post('aigc/task_delete', AigcController::class, 'task_delete', 'aigc');
    Route::get('aigc/package', PackageController::class, 'package', 'aigc');

    Route::post('aigc/stream', ChatController::class, 'stream', 'aigc');
    Route::post('aigc/new_session', ChatController::class, 'new_session', 'aigc');
    Route::get('aigc/sessions', ChatController::class, 'sessions', 'aigc');
    Route::get('aigc/messages', ChatController::class, 'messages', 'aigc');

    Route::group('aigc', UserController::class)
        ->prefix('user/aigc')
        ->sub('user')
        ->get(['index']);
});
