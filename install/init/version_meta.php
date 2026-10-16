<?php

/**
 * DouPHP — install 物料版本元信息读取器（须为 PHP 5.2 可解析语法）
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
 * 读取 install 物料根元信息（install/version.php）。
 *
 * 版本串与最低 PHP / MySQL 要求以 install/version.php 为单一来源（对标升级程序 version.php 的
 * 单一来源约定）：不再散落在各控制器/服务常量与 SQL dump 之中，发版仅需修改 install/version.php
 * （并与 _update/version.php、upgrade/version.php 三份对拷）。
 *
 * 本文件先于 install/run.php 的自动加载器注册被加载，故用全局函数 + 直接 include 实现，
 * 不依赖任何命名空间类；语法保持 PHP 5.2 可解析（PHP 过低时仍能正常解析并输出门禁提示）。
 *
 * @return array array('version' => string, 'php_min' => string, 'mysql_min' => string)
 */
if (!function_exists('douphp_install_version_meta')) {
    function douphp_install_version_meta()
    {
        static $meta = null;
        if ($meta !== null) {
            return $meta;
        }

        $file = dirname(dirname(__FILE__)) . '/version.php';
        $meta = is_file($file) ? include $file : array();
        if (!is_array($meta)) {
            $meta = array();
        }

        return $meta;
    }
}

/**
 * install 最低 PHP 版本（version.php 的 php_min；缺失时兜底 5.6.0）。
 *
 * @return string
 */
if (!function_exists('douphp_install_min_php')) {
    function douphp_install_min_php()
    {
        $meta = douphp_install_version_meta();

        return (isset($meta['php_min']) && (string) $meta['php_min'] !== '') ? (string) $meta['php_min'] : '5.6.0';
    }
}

/**
 * install 最低 MySQL 版本（version.php 的 mysql_min；缺失时兜底 5.7）。
 *
 * @return string
 */
if (!function_exists('douphp_install_min_mysql')) {
    function douphp_install_min_mysql()
    {
        $meta = douphp_install_version_meta();

        return (isset($meta['mysql_min']) && (string) $meta['mysql_min'] !== '') ? (string) $meta['mysql_min'] : '5.7';
    }
}
