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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * install 路由映射表：模块 => 控制器类名（FQCN）
 *
 * URL 格式：install/index.php?route=module/action
 * - 空 route 默认走 IndexController::index
 * - 安装锁存在时强制走 LockController::index（由 Router 控制）
 */
return array(
    'index'   => 'Dou\\Install\\Controller\\IndexController',
    'check'   => 'Dou\\Install\\Controller\\CheckController',
    'setting' => 'Dou\\Install\\Controller\\SettingController',
    'install' => 'Dou\\Install\\Controller\\InstallController',
    'finish'  => 'Dou\\Install\\Controller\\FinishController',
    'lock'    => 'Dou\\Install\\Controller\\LockController',
);
