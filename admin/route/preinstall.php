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
 * Release Date: 2026-10-14
 */

/**
 * preinstall 后台声明式路由
 *
 * 声明 ?route=preinstall/<slug> 形态：GET = 预置模块详情页，DELETE = 隐藏预置项
 * （删除 storage/install/preinstall/<slug>.php 标识文件）。
 * 显式全名（与 admin.geo.cron 同款），不依赖 name 组前缀叠加。
 */

use Dou\Admin\Controller\Preinstall\PreinstallController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::get('preinstall/{slug}', PreinstallController::class, 'show', 'preinstall', 'admin.preinstall.show', array('slug' => '[A-Za-z0-9_\-]+'));
Route::delete('preinstall/{slug}', PreinstallController::class, 'remove', 'preinstall', 'admin.preinstall.remove', array('slug' => '[A-Za-z0-9_\-]+'));
