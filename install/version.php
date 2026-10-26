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
 * 环境最低要求（本文件不再承载版本串）。
 *
 * 安装版本唯一来源：install/data/backup/douphp.sql 的 dou_config.douphp_version
 * （导库后即为站点真实版本，安装流程不再用本文件覆盖）。
 *
 * - php_min   ：最低 PHP 版本（安装入口门禁 / 升级包解压后二次校验，低于即中止）
 * - mysql_min ：最低 MySQL 版本（utf8mb4 字符集自 5.5.3 起支持，为全站编码底座；
 *               与 devtools/mysql-compat-check 判定口径一致）
 *
 * 注意：_update/version.php 与 upgrade/version.php 仍保留 version 字段（升级包的目标版本，
 * 升级引擎据此写 dou_config.douphp_version），两份逐字节一致；本文件不再与之对拷。
 */
return array(
    'php_min' => '5.6.0',
    'mysql_min' => '5.5.3',
);
