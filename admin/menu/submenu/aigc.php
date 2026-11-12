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
 * aigc 模块后台子菜单族声明（族名 = 文件名）
 *
 * 随模块安装 / 卸载生命周期（installed 清单 file_list 维护本文件），
 * 由 {@see \Dou\Admin\Service\Menu\AdminMenuRegistry::submenus()} 装配。
 * 字段语义见 AdminMenuRegistry docblock；匹配裁决与渲染由 AdminNavResolver 完成。
 */
if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

return array(
    'title' => 'aigc',
    'icon' => 'bi-stars',
    'items' => array(
        'aigc',
        'aigc.package',
        'aigc.subscription',
        'aigc.quota',
        'aigc.task',
        'aigc.session',
        'aigc.usage_log',
    ),
    // 项图标（键为路由别名或项 key）：与 items 分离书写，缺项不渲染图标
    'icons' => array(
        'aigc' => 'bi-stars',
        'aigc.package' => 'bi-box-seam',
        'aigc.subscription' => 'bi-calendar2-check',
        'aigc.quota' => 'bi-speedometer2',
        'aigc.task' => 'bi-list-task',
        'aigc.session' => 'bi-chat-dots',
        'aigc.usage_log' => 'bi-clock-history',
    ),
);
