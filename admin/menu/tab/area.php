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
 * area 页面顶部 Tab 组声明（组名 = 文件名）
 *
 * 只匹配地区列表页：分区切换是列表级操作，编辑页的归属由记录自身 lang 决定，
 * 不应出现可切换的其它分区。
 *
 * 项由页面数据 area_tabs 提供（服务端已按“多语言页存在扩展语言”决定是否给值，
 * 为空时 inc/tab_nav.tpl 整体跳过），行字段 name / url / current。
 * 格式语义见 AdminTabResolver docblock 的 source.format = link_list。
 */
if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

return array(
    'match' => array('admin.area'),
    'items' => array(
        array('source' => array('var' => 'area_tabs', 'format' => 'link_list', 'active_var' => 'current')),
    ),
);
