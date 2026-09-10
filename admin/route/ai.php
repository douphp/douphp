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
 * ai 后台声明式路由
 *
 * 声明 ?route=ai[/<sub>]/<action> 形态：AI 创作应用主控制器 + 子控制器（日志（表 ai_log）/
 * 模型（含供应商主表））+ 生成端点四个独立子资源（批量 / 整表单 / 单字段 / 多语言翻译，各自 POST →
 * store，URL = ai/generate/{batch|form|field|translate}）。
 */

use Dou\Admin\Controller\Ai\AiController;
use Dou\Admin\Controller\Ai\GenerateBatchController;
use Dou\Admin\Controller\Ai\GenerateFieldController;
use Dou\Admin\Controller\Ai\GenerateFormController;
use Dou\Admin\Controller\Ai\GenerateTranslateController;
use Dou\Admin\Controller\Ai\KeyController;
use Dou\Admin\Controller\Ai\ModelController;
use Dou\Admin\Controller\Ai\TaskController;
use Dou\Admin\Controller\Ai\AiLogController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::name('admin.')->compositeModule()->group(function () {
    Route::resource('ai', AiController::class)
        ->get(['get_fields'])
        ->post(['action']);

    Route::resource('ai_generate', GenerateBatchController::class)
        ->prefix('ai/generate/batch')
        ->sub('batch')
        ->only(['store']);

    Route::resource('ai_generate', GenerateFormController::class)
        ->prefix('ai/generate/form')
        ->sub('form')
        ->only(['store']);

    Route::resource('ai_generate', GenerateFieldController::class)
        ->prefix('ai/generate/field')
        ->sub('field')
        ->only(['store'])
        ->get(['products'])
        ->post(['preview']);

    Route::resource('ai_generate', GenerateTranslateController::class)
        ->prefix('ai/generate/translate')
        ->sub('translate')
        ->only(['store']);

    Route::group('ai', KeyController::class)
        ->prefix('ai/key')
        ->sub('key')
        ->post(['reset']);

    Route::resource('ai', ModelController::class)
        ->prefix('ai/model')
        ->sub('model')
        ->post(['action', 'toggle_status']);

    Route::group('ai', AiLogController::class)
        ->prefix('ai/log')
        ->sub('log')
        ->get(['index', 'show'])
        ->post(['action'])
        ->delete(['destroy']);

    Route::group('ai', TaskController::class)
        ->prefix('ai/task')
        ->sub('task')
        ->get(['index', 'show'])
        ->post(['store', 'image'])
        ->delete(['destroy']);
});
