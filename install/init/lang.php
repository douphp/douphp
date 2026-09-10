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

return array(
    // 公共
    'douphp'                     => 'DouPHP',
    'yes'                        => '是',
    'no'                         => '否',
    'open'                       => '开启',
    'close'                      => '关闭',
    'write'                      => '可写',
    'no_write'                   => '不可写',
    'not_exist'                  => '不存在',
    'next'                       => '下一步',
    'back'                       => '返回',
    'wrong'                      => '错误',
    'load'                       => '登录',

    // 系统信息
    'os'                         => '服务器操作系统',
    'web_server'                 => 'Web 服务器',
    'php_version'                => 'PHP 版本',
    'mysql_version'              => 'MySQL 版本',
    'gd_version'                 => 'GD 版本',
    'zlib'                       => 'Zlib 支持',
    'ip_version'                 => 'IP 库版本',
    'max_filesize'               => '文件上传的最大大小',
    'timezone'                   => '时区设置',
    'no_timezone'                => '无需设置',
    'socket'                     => 'Socket 支持',

    // welcome
    'welcome'                    => '欢迎您选用DouPHP企业网站管理系统',
    'welcome_agree'              => '我认真阅读并接受以上协议。',

    // check
    'check'                      => '环境检测',
    'check_system'               => '系统环境',
    'check_dir'                  => '目录权限检测',
    'php_version_too_low'        => '需 PHP 5.6.0 或更高版本',

    // setting
    'setting'                    => '配置系统',
    'setting_mysql'              => '数据库配置',
    'setting_host'               => '数据库地址',
    'setting_host_cue'           => '如果无法连接可尝试将地址改为localhost（<a href="javascript:;" onclick="changeHost()">点击更改</a>），或与服务商联系获取',
    'setting_dbuser'             => '数据库账号',
    'setting_dbuser_cue'         => '您的MySQL用户名',
    'setting_dbpass'             => '密码',
    'setting_dbpass_cue'         => 'MySQL密码',
    'setting_dbname'             => '数据库名',
    'setting_dbname_cue'         => '将DouPHP安装到哪个数据库？',
    'setting_prefix'             => '数据前缀',
    'setting_prefix_cue'         => '如果您希望在同一个数据库安装多个DouPHP，请修改前缀。',
    'setting_manager'            => '管理员帐号',
    'setting_username'           => '管理员用户名',
    'setting_username_cue'       => '用户名至少包含4个字符，需以字母开头。只能使用字母、数字、下划线',
    'setting_password'           => '登录密码',
    'setting_password_cue'       => '密码至少包含6个字符。可使用字母，数字和符号。',
    'setting_password_confirm'   => '登录密码确认',
    'setting_password_confirm_cue' => '请再次输入登录密码',
    'setting_email'              => '安全邮箱',
    'setting_email_cue'          => '可不填写（设置邮箱，主要用于密码找回）',
    'setting_test_data'          => '测试数据',
    'setting_test_data_cue'      => '测试数据可以方便您体验系统，不安装也不会影响系统使用。',
    'setting_test_data_yes'      => '安装',
    'setting_test_data_no'       => '不安装',
    'setting_submit'             => '安装DouPHP',

    // cue
    'cue_connect'                => '数据库连接失败! 请检查连接参数。',
    'cue_no_this_dbname'         => '数据库不存在! 且无法自动创建。',
    'cue_create_db_failed'       => '数据库不存在且自动创建失败',
    'cue_username_empty'         => '请输入管理员名称',
    'cue_username_wrong'         => '用户名至少包含4个字符只能包含字母、数字、下划线和@符号',
    'cue_password_empty'         => '请填写管理员登录密码',
    'cue_password_wrong'         => '密码至少包含6个字符只能包含字母，数字和符号',
    'cue_password_confirm_empty' => '请再次输入登录密码',
    'cue_password_confirm_wrong' => '两次输入密码不正确',
    'cue_email_wrong'            => '请输入正确的邮箱地址',
    'cue_illegal'                => '非法操作',

    // finish
    'finish'                     => 'DouPHP安装完成。您开始开始管理你的网站内容！',
    'finish_title'               => '安装成功！',
    'finish_password'            => '您设定的密码！',

    // lock
    'lock'                       => '安装程序已经被锁定。',
    'lock_content'               => '如果您确定要重新安装 DouPHP，请删除 storage 目录下的 install.lock 文件。',

    // 通用消息
    'dou_msg_success'            => '操作成功',
);
