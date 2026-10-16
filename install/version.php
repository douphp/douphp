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
 * Release Date: 2026-10-16
 */
if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 目标版本与环境要求（单一来源；本文件在 _update/、upgrade/、install/ 三份逐字节一致，改后须对拷）。
 *
 * - version   ：写入 dou_config.douphp_version 的版本串（全新安装 / 升级完成后；发版时唯一手动修改点）
 * - php_min   ：最低 PHP 版本（安装入口门禁 / 升级包解压后二次校验，低于即中止）
 * - mysql_min ：最低 MySQL 版本（与 devtools/mysql-compat-check 判定口径一致）
 */
return array(
    'version' => 'v2.0 Release 20261016',
    'php_min' => '5.6.0',
    'mysql_min' => '5.7',
);
