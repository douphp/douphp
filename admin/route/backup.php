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
 *
 * 升级还原点（快照）另起一组同名前缀路由（SnapshotController）：
 * snapshot_restore 为 any（确认 POST + dou_msg 链 GET 续跑，见 CsrfMiddleware::getTokenRoutes），
 * snapshot_cleanup 为 POST（按留存策略清理，二次确认），
 * snapshot_destroy 为 DELETE（dou_msg 二次确认表单以 _method=DELETE 提交）。
 */

use Dou\Admin\Controller\Backup\BackupController;
use Dou\Admin\Controller\Backup\SnapshotController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::group('backup', BackupController::class)->name('admin.')
    ->prefix('backup')
    ->get(['index', 'restore', 'down'])
    ->any(['backup', 'import'])
    ->delete(['destroy']);

Route::group('backup', SnapshotController::class)->name('admin.')
    ->prefix('backup')
    ->get(['snapshot', 'snapshot_down', 'snapshot_rescue', 'snapshot_abandon', 'snapshot_maintenance_off'])
    ->any(['snapshot_restore'])
    ->post(['snapshot_cleanup'])
    ->delete(['snapshot_destroy']);
