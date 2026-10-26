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
 * form 后台声明式路由
 *
 * 声明 ?route=form[/<sub>]/<action> 形态：表单主信息 + 预置模板引导页 + 字段子资源 + 提交数据子资源。
 *
 * 三个控制器共用 module=form（不开启 compositeModule），因此权限键、审计 routeModule 与
 * 侧栏高亮（admin.form.*）完全一致，只有 URL 与路由名按子资源细分：
 *   - form[/{id}[/edit]]        表单管理 / 主信息编辑
 *   - form/field[/{id}]         字段列表 + 字段新增 / 编辑（同一页面，field_id 走 query）
 *   - form/data[/{id}]          提交数据列表与详情
 */

use Dou\Admin\Controller\Form\FormController;
use Dou\Admin\Controller\Form\FormDataController;
use Dou\Admin\Controller\Form\FormFieldController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::resource('form', FormController::class)->name('admin.')
    ->get([
        'form_list',
        'template',
    ])
    ->post([
        'template_store',
    ]);

Route::resource('form', FormFieldController::class)
    ->prefix('form/field')
    ->sub('field')
    ->name('admin.')
    ->only([
        'index',
        'store',
        'update',
        'destroy',
    ]);

Route::resource('form', FormDataController::class)
    ->prefix('form/data')
    ->sub('data')
    ->name('admin.')
    ->only([
        'index',
        'show',
        'destroy',
    ])
    ->post([
        'check',
        'mark_all',
    ]);
