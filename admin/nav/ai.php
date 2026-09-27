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
 * Release Date: 2026-09-26
 */

/**
 * ai 模块后台子菜单族声明（族名 = 文件名）
 *
 * 随模块安装 / 卸载生命周期（installed 清单 file_list 维护本文件），
 * 由 {@see \Dou\Admin\Service\Menu\AdminMenuRegistry::subMenus()} 装配。
 * 字段语义见 AdminMenuRegistry docblock；匹配裁决与渲染由 AdminNavResolver 完成。
 */

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

return array(
    'title' => 'ai',
    'icon' => 'bi-robot',
    'items' => array(
        array(
            'key' => 'ai', 'name' => 'ai', 'link' => 'admin.ai',
            'match' => array('admin.ai.*', 'admin.ai_generate.*'),
        ),
        array(
            'key' => 'model', 'name' => 'ai_model', 'link' => 'admin.ai.model',
            'match' => array('admin.ai.model.*', 'admin.ai.key.*'),
        ),
        array(
            'key' => 'log', 'name' => 'ai_log', 'link' => 'admin.ai.log',
            'match' => array('admin.ai.log.*'),
        ),
        array(
            'key' => 'task', 'name' => 'ai_task', 'link' => 'admin.ai.task',
            'match' => array('admin.ai.task.*'),
        ),
    ),
);
