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

namespace Dou\Core\Service\System;

use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 系统恒定常量读取（Domain 读模型）。
 *
 * 唯一职责：把 config/system.php 解析成 `key => array(values)` 形式的原始数组，
 * 供 {@see \Dou\Core\Bootstrap\SystemBootstrap} 灌入 Config 的 `system.*` 命名空间。
 *
 * 与 {@see ModuleSettingReader}（读 config/module.php 的用户可调模块账本）分工分离：
 * 本类读取的是不随模块安装/卸载改写的框架层常量。
 */
class SystemConstantsReader extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 读取系统常量原始数组。
     *
     * @return array
     */
    public function read()
    {
        $file = CONFIG_PATH . 'system.php';
        if (!file_exists($file)) {
            return array();
        }
        $loaded = include $file;

        return is_array($loaded) ? $loaded : array();
    }
}
