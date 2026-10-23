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
 * metadata 后台声明式路由
 *
 * 扩展字段定义管理采用标准资源形态（与 article 一致）：
 *   - 列表：metadata/fields（旧入口，现由设置页 Tab 进入）与 metadata（资源 index）
 *   - 新建：metadata/create（GET）/ metadata（POST store）
 *   - 编辑：metadata/{id}/edit（GET）/ metadata/{id}（PUT update）
 *   - 删除：metadata/{id}（DELETE destroy，经 #dou-delete-form 方法伪装提交）
 */

use Dou\Admin\Controller\Metadata\DraftController;
use Dou\Admin\Controller\Metadata\MetadataController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::name('admin.')->group(function () {
    Route::resource('metadata', MetadataController::class)
        ->get(['fields']);

    Route::group('metadata', DraftController::class)
        ->prefix('metadata/draft')
        ->sub('draft')
        ->root('__noop__')
        ->post(array('upload', 'release'));
});
