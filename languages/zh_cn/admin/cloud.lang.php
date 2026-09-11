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

// 云服务
$_LANG['cloud'] = '云服务';
$_LANG['cloud_buy_vip'] = '开通VIP会员';
$_LANG['cloud_admin_home'] = '返回管理中心';
$_LANG['cloud_handle'] = '在线安装';
$_LANG['cloud_update_home'] = '返回更新主页';
$_LANG['cloud_module'] = '模块';
$_LANG['cloud_module_home'] = '返回模块管理中心';
$_LANG['cloud_plugin'] = '插件';
$_LANG['cloud_plugin_enable'] = '启用插件';
$_LANG['cloud_plugin_home'] = '返回插件管理中心';
$_LANG['cloud_theme'] = '模板';
$_LANG['cloud_theme_enable'] = '启用模板';
$_LANG['cloud_theme_home'] = '返回模板管理中心';
$_LANG['cloud_mobile_theme_home'] = '返回手机版模板管理';
$_LANG['cloud_miniprogram'] = '小程序';
$_LANG['cloud_miniprogram_home'] = '返回小程序管理中心';
$_LANG['cloud_system'] = '系统';
$_LANG['cloud_handle_success'] = '成功安装';
$_LANG['cloud_title_install'] = '正在安装';
$_LANG['cloud_title_install_for_theme'] = '正在安装模板 d% 所需的';
$_LANG['cloud_title_update'] = '正在更新';
$_LANG['cloud_order'] = '购买';
$_LANG['cloud_order_submit'] = '提交订单';

// 提示信息
$_LANG['cloud_frame_cue'] = '这个功能需要 iframe 的支持。您可能禁止了 iframe 的显示，或您的浏览器不支持此功能。';
$_LANG['cloud_pay_fail'] = '付款失败';
$_LANG['cloud_pay_success'] = '付款成功';
$_LANG['cloud_closed'] = '系统已关闭在线升级功能，请先开启！';
$_LANG['cloud_connect_failed'] = '无法连接云服务，请稍后重试或检查云服务地址配置。';
$_LANG['cloud_api_base_missing'] = '未配置云服务接口地址，无法使用在线安装等功能。';

// 解压缩
$_LANG['cloud_unzip_ing'] = '正在解压缩安装包…';
$_LANG['cloud_unzip_wrong'] = '压缩包解压失败';
$_LANG['cloud_writeable_denied'] = '以下目录没有写入权限：%s。安装/升级需要写入全站关键目录，请将站点根目录及其全部子目录设置为可写（Linux 下可递归 chmod 777）后重试；安装完成后建议收回 config/ 目录的写权限。';
$_LANG['cloud_unzip_missing'] = '未找到已下载的安装包文件，请关闭本页后重新发起安装或更新。';

// 下载
$_LANG['cloud_down_ing_0'] = '正在从 ';
$_LANG['cloud_down_ing_1'] = ' 下载安装包…';
$_LANG['cloud_down_wrong'] = '下载失败';
$_LANG['cloud_local_zip_missing'] = '未在 cache 目录找到本地安装包，请将模块压缩包（与模块标识同名的 .zip）放到 cache 后再试。';
$_LANG['cloud_down_upstream_not_found'] = '云端未找到该安装包（可能版本或编号不存在），请确认后再试。';
$_LANG['cloud_down_upstream_unavailable'] = '下载服务暂时不可用或上游镜像无法访问，请稍后再试。';
$_LANG['cloud_down_upstream_misconfigured'] = '云端下载服务未正确配置上游地址，请联系管理员。';

// 安装
$_LANG['cloud_install_ing'] = '正在安装 ';
$_LANG['cloud_install_0'] = '安装 ';
$_LANG['cloud_install_1'] = ' 成功';
$_LANG['cloud_install_next'] = '即将安装下一个模块';
$_LANG['cloud_install_theme'] = '即将安装模板';
$_LANG['cloud_below_mini_version_support'] = '您的系统版本过低，请升级后重新安装！';
$_LANG['cloud_install_repeat'] = '已经存在，不要重复安装';
$_LANG['cloud_sql_repeat'] = '数据表已经存在于数据库中，不要重复安装';
$_LANG['cloud_sql_wrong'] = '数据库无法导入、';
$_LANG['cloud_systemfile_wrong'] = '无法修改系统配置文件';

// 在线安装多步流程（cloud/install）
$_LANG['cloud_install_retry'] = '重新安装';
$_LANG['cloud_install_session_missing'] = '安装会话已过期或不存在，请返回安装入口重新发起';
$_LANG['cloud_install_request_failed'] = '安装步骤请求失败（网络中断、超时或服务端返回了非 JSON）。解压大包时若较慢，请在服务器上适当提高 max_execution_time 与可用内存。';
$_LANG['cloud_install_step_failed_generic'] = '安装步骤失败，请稍后重试或查看服务器与 PHP 错误日志。';

// 在线安装权限矩阵（与 download/install-resolve 的 auth_status 对齐）
$_LANG['cloud_install_login_required'] = '请先设置云账号后再下载安装。';
$_LANG['cloud_install_login_cloud_account'] = '设置云账号';
$_LANG['cloud_install_not_purchased'] = '该付费扩展尚未购买，请先购买后再安装。';
$_LANG['cloud_install_buy_extend'] = '购买扩展';
$_LANG['cloud_install_vip_required'] = '该扩展为 VIP 专享，您当前没有 VIP，请先购买 VIP。';
$_LANG['cloud_install_buy_vip'] = '购买 VIP';
$_LANG['cloud_install_vip_expired'] = '您的 VIP 已过期，请续费后再安装 VIP 专享扩展。';
$_LANG['cloud_install_renew_vip'] = '续费 VIP';
$_LANG['cloud_install_resolve_unavailable'] = '云服务暂不可用，请稍后重试。';

// 升级
$_LANG['cloud_update'] = '系统更新';
$_LANG['cloud_update_0'] = '更新 ';
$_LANG['cloud_update_next'] = '即将更新下一个模块';

// 云账户设置
$_LANG['cloud_account_title'] = 'Dou云账户';
$_LANG['cloud_account'] = '设置云账户';
$_LANG['cloud_account_cue'] = '您的云账户没有设置，或者设置不正确，请检查';
$_LANG['cloud_account_intro'] = '设置云账户后，可使用在线安装模块、模板等云服务功能';
$_LANG['cloud_account_user'] = '云账户用户名';
$_LANG['cloud_account_password'] = '云账户密码';
$_LANG['cloud_account_success'] = '设置云账户成功';
$_LANG['cloud_account_register'] = '立即注册';
$_LANG['cloud_account_register_0'] = '没有账户？';
$_LANG['cloud_account_seted'] = '已经设置云账户';
$_LANG['cloud_account_reset'] = '重新设置';
$_LANG['cloud_account_user_wrong'] = '云账户用户名应为有邮箱或手机号，您输入格式不正确或者为空';
$_LANG['cloud_account_wrong'] = '云账户用户名或密码输入不正确';
$_LANG['cloud_account_clean'] = '清空云账户';
$_LANG['cloud_account_clean_success'] = '清空云账户成功';

// 去版权
$_LANG['cloud_copyright'] = '去版权';
$_LANG['cloud_copyright_success'] = '前台版权去除成功！';
$_LANG['cloud_copyright_no_vip'] = '该账号或者该域名没有开通VIP会员！';

// 云端扩展列表（inc/cloud_extend_list.tpl）
$_LANG['cloud_extend_filter_all'] = '全部';
$_LANG['cloud_extend_filter_free_theme'] = '免费模板';
$_LANG['cloud_extend_price_label'] = '费用：';
$_LANG['cloud_extend_author_label'] = '作者：';
$_LANG['cloud_extend_need_module_label'] = '支持模块：';
$_LANG['cloud_extend_preview_theme'] = '预览模板';
$_LANG['cloud_extend_download_count'] = '下载量：';
$_LANG['cloud_extend_miniprogram_badge'] = '适配小程序';
$_LANG['cloud_extend_update_time_label'] = '更新时间：';
$_LANG['cloud_extend_min_version_label'] = '最低版本要求：';
$_LANG['cloud_extend_detail'] = '详细信息';
