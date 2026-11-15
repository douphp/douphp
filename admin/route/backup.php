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
 * backup 后台声明式路由
 *
 * 声明 ?route=backup[/<action>] 形态：数据库备份（备份 / 还原 / 导入 / 下载 / 删除）。
 * backup / import 使用 any：首屏 POST 提交与 dou_msg 续传 GET 共用同一路由名。
 */

use Dou\Admin\Controller\Backup\BackupController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::group('backup', BackupController::class)->name('admin.')
    ->prefix('backup')
    ->get(['index', 'restore', 'down'])
    ->any(['backup', 'import'])
    ->delete(['destroy']);
