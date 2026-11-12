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
 * aigc 后台声明式路由
 *
 * 声明 ?route=aigc[/<sub>]/<action> 形态：会员 AI 应用主控制器 + 子控制器
 * （套餐 / 订阅 / 配额 / 任务 / 会话 / 用量日志）。
 */

use Dou\Admin\Controller\Aigc\ApplicationController;
use Dou\Admin\Controller\Aigc\FieldController;
use Dou\Admin\Controller\Aigc\PackageController;
use Dou\Admin\Controller\Aigc\QuotaController;
use Dou\Admin\Controller\Aigc\SessionController;
use Dou\Admin\Controller\Aigc\SubscriptionController;
use Dou\Admin\Controller\Aigc\TaskController;
use Dou\Admin\Controller\Aigc\UsageLogController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

// aigc 运行时硬依赖 ai 模块下发的 core/service/ai 类；缺失时不注册路由，避免容器反射致命错误
if (!class_exists('Dou\\Core\\Service\\Ai\\AiGateway')) {
    return;
}

Route::name('admin.')->compositeModule()->group(function () {
    Route::resource('aigc', ApplicationController::class)
        ->post(['action']);

    Route::resource('aigc', FieldController::class)
        ->prefix('aigc/field')
        ->sub('field')
        ->only([
            'index',
            'store',
            'update',
            'destroy',
        ]);

    Route::resource('aigc', PackageController::class)
        ->prefix('aigc/package')
        ->sub('package')
        ->post(['batch']);

    Route::group('aigc', SubscriptionController::class)
        ->prefix('aigc/subscription')
        ->sub('subscription')
        ->get(['index', 'show'])
        ->post(['action'])
        ->delete(['destroy']);

    Route::resource('aigc', QuotaController::class)
        ->prefix('aigc/quota')
        ->sub('quota')
        ->post(['action']);

    Route::group('aigc', TaskController::class)
        ->prefix('aigc/task')
        ->sub('task')
        ->get(['index', 'show'])
        ->delete(['destroy']);

    Route::group('aigc', SessionController::class)
        ->prefix('aigc/session')
        ->sub('session')
        ->get(['index', 'show'])
        ->post(['close', 'action'])
        ->delete(['destroy']);

    Route::group('aigc', UsageLogController::class)
        ->prefix('aigc/usage_log')
        ->sub('usage_log')
        ->get(['index', 'show'])
        ->post(['action'])
        ->delete(['destroy']);
});
