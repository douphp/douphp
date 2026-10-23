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

$_LANG['metadata'] = '扩展字段';
$_LANG['metadata_slug'] = '字段标识';
$_LANG['metadata_label'] = '显示名';
$_LANG['metadata_type'] = '字段类型';
$_LANG['metadata_required'] = '必填';
$_LANG['metadata_limit'] = '数量上限';
$_LANG['metadata_sort'] = '排序';
$_LANG['metadata_status'] = '状态';
$_LANG['metadata_status_on'] = '启用';
$_LANG['metadata_status_off'] = '禁用';
$_LANG['metadata_edit'] = '编辑扩展字段';
$_LANG['metadata_create'] = '新增扩展字段';
$_LANG['metadata_delete_confirm'] = '删除后已填写的数据与附件仍会保留，重建同标识字段即可恢复展示。确定删除？';
$_LANG['metadata_options'] = '选项';
$_LANG['metadata_options_tip'] = 'select / radio / checkbox 使用，每行一个';
$_LANG['metadata_extensions'] = '允许扩展名';
$_LANG['metadata_extensions_tip'] = '仅 file 类型生效；可填一个或多个扩展名，多个用英文逗号分隔（例：jpg,png,pdf），不要带点；只能填写平台基线内的扩展名，留空表示使用全部基线';
$_LANG['metadata_extensions_baseline'] = '平台基线';
$_LANG['metadata_max_kb'] = '大小上限(KB)';
$_LANG['metadata_max_kb_tip'] = '仅 file 类型生效；0 表示平台默认，且不能超过平台值';
$_LANG['metadata_limit_tip'] = 'gallery / checkbox 使用；0 表示 gallery 默认 6 个槽位、checkbox 不限制';
$_LANG['metadata_tip'] = '提示文案';
$_LANG['metadata_slug_tip'] = '小写字母开头，仅含小写字母、数字、下划线，最长 30 位；创建后不可修改';
$_LANG['metadata_cue_not_ready'] = '扩展字段定义表尚未就绪，请先升级系统。';
$_LANG['metadata_default_language'] = '默认语言';
$_LANG['metadata_list_title'] = '扩展字段';
$_LANG['metadata_list_empty'] = '暂无扩展字段';
$_LANG['metadata_field_unit'] = '个字段';
$_LANG['metadata_module'] = '所属模块';
$_LANG['metadata_module_locked'] = '所属模块由列表分组入口决定，不可修改';
$_LANG['metadata_create_need_module'] = '请从对应模块分组点击「新增」创建扩展字段';
$_LANG['metadata_section_basic'] = '基础信息';
$_LANG['metadata_section_control'] = '控件属性';
$_LANG['metadata_section_display'] = '显示与状态';
$_LANG['metadata_type_locked'] = '字段类型创建后不可修改，如需变更请删除后重建';
$_LANG['metadata_type_switch_tip'] = '切换类型会显示该类型可用的配置项';
$_LANG['metadata_sort_tip'] = '「排序」只在所属模块内生效，数值越小越靠前。';
$_LANG['metadata_create_success'] = '扩展字段已创建';
$_LANG['metadata_update_success'] = '扩展字段已保存';
$_LANG['metadata_delete_success'] = '扩展字段已删除';
$_LANG['metadata_inherit_default'] = '继承默认';
$_LANG['metadata_clear'] = '清除';
$_LANG['metadata_download'] = '下载';

$_LANG['metadata_type_input'] = '单行文本';
$_LANG['metadata_type_textarea'] = '多行文本';
$_LANG['metadata_type_number'] = '数字';
$_LANG['metadata_type_date'] = '日期';
$_LANG['metadata_type_select'] = '下拉选择';
$_LANG['metadata_type_radio'] = '单选';
$_LANG['metadata_type_checkbox'] = '多选';
$_LANG['metadata_type_switch'] = '开关';
$_LANG['metadata_type_image'] = '单图';
$_LANG['metadata_type_gallery'] = '多图';
$_LANG['metadata_type_file'] = '文件';
$_LANG['metadata_type_editor'] = '富文本';

$_LANG['metadata_error_module'] = '所属模块不合法';
$_LANG['metadata_error_slug'] = '字段标识必须为小写字母开头，仅含小写字母、数字、下划线，最长 30 位';
$_LANG['metadata_error_label'] = '显示名不能为空且不能超过 150 个字符';
$_LANG['metadata_error_type'] = '字段类型不合法';
$_LANG['metadata_error_slug_locked'] = '字段标识创建后不可修改';
$_LANG['metadata_error_type_locked'] = '字段类型创建后不可修改';
$_LANG['metadata_error_options'] = '选择类字段必须填写至少一个选项';
$_LANG['metadata_error_max_kb'] = '文件大小上限不能超过平台默认值';
$_LANG['metadata_error_limit'] = '数量上限必须在 0 到 20 之间';
$_LANG['metadata_error_not_ready'] = '扩展字段定义表尚未就绪，请先升级系统';
$_LANG['metadata_error_slug_exists'] = '该模块下字段标识已存在';
$_LANG['metadata_error_limit_reached'] = '单个模块的扩展字段数量已达上限';
$_LANG['metadata_error_not_found'] = '扩展字段不存在';
$_LANG['metadata_error_extension_format'] = '扩展名格式不合法';
$_LANG['metadata_error_extension_dangerous'] = '包含被禁止的危险扩展名';
$_LANG['metadata_error_extension_outside'] = '扩展名超出平台允许范围';
$_LANG['metadata_error_extension_empty'] = '至少填写一个允许的扩展名';
$_LANG['metadata_error_attachment'] = '附件编号不合法';
$_LANG['metadata_error_attachment_tamper'] = '附件编号与当前记录不一致，请刷新后重试';
$_LANG['metadata_error_upload'] = '文件上传失败';
$_LANG['metadata_error_encode'] = '扩展字段数据编码失败';
$_LANG['metadata_error_save_failed'] = '扩展字段保存失败，请检查字段内容编码或数据库日志';
$_LANG['metadata_error_label_too_long'] = '显示名不能超过 150 个字符';
