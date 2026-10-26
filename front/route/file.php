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
 * file 前台声明式路由
 *
 * 通用文件上传能力，与业务模块无关，统一要求登录态（auth_modes['file'] = 'required'）：
 *   - file/box     上传并按 prefix 回渲染列表 HTML
 *   - file/destroy 删除单个文件并回渲染列表 HTML
 */

use Dou\Core\Web\Routing\Route;
use Dou\Front\Controller\File\FileController;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::post('file/box', FileController::class, 'box', 'file', 'file.box');
Route::post('file/destroy', FileController::class, 'destroy', 'file', 'file.destroy');
