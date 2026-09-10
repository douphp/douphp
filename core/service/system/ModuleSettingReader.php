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
 * 模块账本原始数据读取（Domain 读模型）。
 *
 * 唯一职责：把 config/module.php（或 DOU_MODULE_SETTING 常量序列化值）
 * 解析成 `key => array(values)` 形式的原始数组。
 *
 * 上层 {@see CoreModuleSettings} / {@see \Dou\Admin\Service\Workspace\WorkspaceBuilder}
 * 在此基础上做进一步的字段筛选与合并。
 */
class ModuleSettingReader extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 读取系统设定原始数组。
     *
     * @return array
     */
    public function read()
    {
        if (defined('DOU_MODULE_SETTING')) {
            $module = unserialize(DOU_MODULE_SETTING);
            return is_array($module) ? $module : array();
        }

        $file = CONFIG_PATH . 'module.php';
        if (!file_exists($file)) {
            return array();
        }
        $loaded = include $file;
        return is_array($loaded) ? $loaded : array();
    }
}
