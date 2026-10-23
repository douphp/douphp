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
 * Release Date: 2026-10-21
 */

/**
 * setting 页面顶部 Tab 组声明（组名 = 文件名）
 *
 * 由 {@see \Dou\Admin\Service\Menu\AdminTabResolver::groups()} 装配。
 * 本组是设置体系的唯一信源：主设置页 admin.setting 与关联页扩展字段
 * admin.metadata.fields、语言设置 admin.language.system 在此一处声明，
 * 关联页不再各自建组（同 user 子菜单族的组织方式）；非主页面复用时
 * 锚点项自动改为跳回 admin.setting#锚点。
 * 设置分组逐项声明（config_group 标记，SettingService 据此装配正文 pane）；
 * parameter_system_list / lang_list 由页面数据提供，其余为静态项。
 * 字段语义见 AdminTabResolver docblock。
 */
if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

return array(
    'match' => array('setting', 'metadata', 'language.system'),
    // 主页面：非主页面复用本组时，锚点项渲染为 admin.setting#锚点 链接
    'home' => 'admin.setting',
    'items' => array(
        // 设置分组：逐项声明，与设置页 tab-pane id 一一对应
        array('key' => 'main', 'name' => 'config_setting_main', 'anchor' => 'main', 'config_group' => true),
        array('key' => 'customer', 'name' => 'config_setting_customer', 'anchor' => 'customer', 'config_group' => true),
        array('key' => 'display', 'name' => 'config_setting_display', 'anchor' => 'display', 'config_group' => true),
        array('key' => 'seo', 'name' => 'config_setting_seo', 'anchor' => 'seo', 'config_group' => true),
        // 扩展字段：原「自定义属性」下线后由 metadata 接管，资源 index 与 fields 两个入口共用高亮
        array('key' => 'metadata', 'name' => 'metadata', 'link' => 'admin.metadata.fields', 'active' => array('metadata')),
        array('key' => 'mail', 'name' => 'config_setting_mail', 'anchor' => 'mail', 'config_group' => true),
        // 自定义参数：有系统参数行时才出现
        array('key' => 'parameter', 'name' => 'parameter', 'anchor' => 'parameter', 'when_var' => 'parameter_system_list'),
        // 短信设置：功能开启时跳独立页面
        array('key' => 'sms', 'name' => 'sms', 'link' => 'admin.sms', 'when' => array('feature' => 'sms')),
        // 语言设置：每个语言一条跳转链接，语言页编辑该语言包时高亮（cur 由页面数据提供）
        array('source' => array('var' => 'lang_list', 'format' => 'lang', 'active_var' => 'cur')),
    ),
);
