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

// 表单 --------
$_LANG['form'] = '表单';
$_LANG['form_list'] = '表单管理';
$_LANG['form_create'] = '添加表单';
$_LANG['form_edit'] = '编辑表单';
$_LANG['form_delete'] = '删除表单';
$_LANG['form_url'] = '表单链接';

// 表单属性
$_LANG['form_name'] = '表单名称';
$_LANG['form_slug'] = '英文标记';
$_LANG['form_content'] = '表单介绍';
$_LANG['form_success_message'] = '提交成功提示';
$_LANG['form_success_url'] = '提交后跳转链接';
$_LANG['form_captcha'] = '启用验证码';
$_LANG['form_require_login'] = '仅登录会员可提交';
$_LANG['form_spam_limit'] = '同 IP 每小时上限';
$_LANG['form_notify_email'] = '新提交通知邮箱';
$_LANG['form_status'] = '表单状态';
$_LANG['form_status_0'] = '关闭';
$_LANG['form_status_1'] = '开启';
$_LANG['form_sort'] = '排序';
$_LANG['form_created_at'] = '添加时间';
$_LANG['form_field_count'] = '字段数';
$_LANG['form_data_count'] = '提交数';
$_LANG['form_list_empty'] = '还没有表单，点击右上角按钮添加';
$_LANG['form_nav_empty'] = '还没有表单';
$_LANG['form_create_success'] = '表单已创建，请继续添加字段';
$_LANG['form_update_success'] = '表单已更新';

// 嵌入代码（表单列表 → 弹窗展示可复制的 HTML 示例）
$_LANG['form_embed'] = '嵌入代码';
$_LANG['form_embed_cue'] = '把上面的 HTML 粘到任意前台 .dwt 模板即可使用该表单；$form.英文标记 由框架自动注入，控件可按需改写。';
$_LANG['form_embed_copy'] = '复制代码';
$_LANG['form_embed_js'] = '代码末尾已附带前台脚本调用：默认主题的 js 目录自带这些文件；若贴到其他模板，请把 theme/default/js 下的 alpine.min.js、dou.form.js、form.js 复制到当前模板的 js 目录（表单含上传字段时再连带 file.js）。';
$_LANG['form_embed_no_field'] = '该表单还没有字段，先在字段列表添加';

$_LANG['form_slug_cue'] = '用于模板调用，如 {$form.quote.contact.name}，小写字母开头，可含数字与下划线';
$_LANG['form_slug_reserved'] = 'd% 是系统保留的模板键名，请更换';
$_LANG['form_slug_exists'] = '英文标记 d% 已被其他表单占用';
$_LANG['form_success_message_cue'] = '留空则使用系统默认提示';
$_LANG['form_success_url_cue'] = '留空则提交后进入提交成功提示页';
$_LANG['form_captcha_cue'] = '需同时开启站点验证码总开关才会生效';
$_LANG['form_require_login_cue'] = '开启后游客看不到提交按钮，上传字段始终要求登录';
$_LANG['form_spam_limit_cue'] = '同一 IP 每小时允许的提交次数，0 表示不限制';
$_LANG['form_notify_email_cue'] = '预留字段，暂不发送邮件';

$_LANG['form_section_basic'] = '基本信息';
$_LANG['form_section_submit'] = '提交行为';
$_LANG['form_section_fields'] = '表单字段';

// 表单字段 -------
$_LANG['form_field'] = '表单字段';
$_LANG['form_field_list'] = '字段列表';
$_LANG['form_field_create'] = '添加字段';
$_LANG['form_field_edit'] = '编辑字段';
$_LANG['form_field_delete'] = '删除字段';
$_LANG['form_field_empty'] = '还没有字段，点击右上角添加';
$_LANG['form_field_manage'] = '管理字段';
$_LANG['form_field_list_cue'] = '当前表单：d%，字段改动会立即反映到前台表单';

$_LANG['form_field_name'] = '字段名称';
$_LANG['form_field_slug'] = '字段键名';
$_LANG['form_field_type'] = '类型';
$_LANG['form_field_unit'] = '单位';
$_LANG['form_field_option'] = '可选项';
$_LANG['form_field_placeholder'] = '输入提示';
$_LANG['form_field_tip'] = '字段说明';
$_LANG['form_field_default_value'] = '默认值';
$_LANG['form_field_validate'] = '校验规则';
$_LANG['form_field_width'] = '栅格宽度';
$_LANG['form_field_must_fill'] = '填写要求';
$_LANG['form_field_must_fill_0'] = '可选';
$_LANG['form_field_must_fill_1'] = '必填';
$_LANG['form_field_limit'] = '最多上传数';
$_LANG['form_field_limit_checkbox'] = '最多可选数';
$_LANG['form_field_extensions'] = '允许扩展名';
$_LANG['form_field_max_size'] = '单文件上限';
$_LANG['form_field_sort'] = '排序';
$_LANG['form_field_custom'] = '模板自定义字段';
$_LANG['form_field_create_success'] = '字段已添加';
$_LANG['form_field_update_success'] = '字段已更新';
$_LANG['form_field_delete_success'] = '字段已删除';
$_LANG['form_agree_checked'] = '已同意';
$_LANG['form_agree_unchecked'] = '未同意';

$_LANG['form_field_slug_cue'] = '提交时的 name 与模板调用的键名，小写字母开头，可含数字与下划线';
$_LANG['form_field_slug_exists'] = '字段键名 d% 在本表单内已存在';
$_LANG['form_field_reserved'] = 'd% 是表单保留键名（如 name / content），请更换';
$_LANG['form_field_option_cue'] = '每行一个纯值，下拉 / 单选 / 多选使用';
$_LANG['form_field_placeholder_cue'] = '输入框灰色提示文字；下拉选择作为「请选择」的占位文案，勾选协议作为勾选框旁的协议文字，日期控件不支持提示文字、仅在悬停时以标题显示';
$_LANG['form_field_validate_cue'] = '多条用逗号分隔；切换字段类型会自动带入该类型的默认规则，可直接修改或删除';
$_LANG['form_validate_email'] = '邮箱格式';
$_LANG['form_validate_url'] = '网址格式';
$_LANG['form_validate_phone'] = '11 位手机号';
$_LANG['form_validate_numeric'] = '数字';
$_LANG['form_validate_date'] = '日期格式';
$_LANG['form_validate_min'] = '至少 2 字';
$_LANG['form_validate_max'] = '最多 200 字';
$_LANG['form_validate_min_value'] = '不小于 0';
$_LANG['form_validate_max_value'] = '不大于 1000';
$_LANG['form_validate_integer'] = '整数';
$_LANG['form_validate_idcard'] = '身份证';
$_LANG['form_validate_postcode'] = '邮政编码';
$_LANG['form_validate_qq'] = 'QQ 号';
$_LANG['form_validate_regex'] = '正则';
$_LANG['form_validate_same'] = '与某字段相同';
$_LANG['form_field_width_cue'] = '桌面端占几列（共 12 列）：6 为两列并排、4 为三列并排、12 为独占一行；手机上始终独占一行';
$_LANG['form_field_limit_cue'] = '多图 / 附件最多上传数量，单图固定 1 张';
$_LANG['form_field_limit_checkbox_cue'] = '多选最多能勾选几项，0 表示不限';
$_LANG['form_field_default_value_cue'] = '选项类需与选项文字完全一致；多选默认值用逗号分隔多个值';
$_LANG['form_field_extensions_cue'] = '逗号分隔，留空则使用系统上传策略';
$_LANG['form_field_extensions_baseline'] = '系统默认允许';
$_LANG['form_field_max_size_cue'] = '单位 KB，0 表示使用系统上传策略';
$_LANG['form_field_slug_copy_cue'] = '点击复制字段键名';
$_LANG['form_field_section_attr'] = '字段属性';
$_LANG['form_field_section_upload'] = '上传限制';

// 用户提交的数据 -------
$_LANG['form_data'] = '表单数据';
$_LANG['form_data_list'] = '数据列表';
$_LANG['form_data_delete'] = '删除表单数据';
$_LANG['form_data_view'] = '查看提交';
$_LANG['form_data_empty'] = '暂无提交数据';

$_LANG['form_data_content'] = '提交内容';
$_LANG['form_data_form'] = '所属表单';
$_LANG['form_data_ip'] = 'IP 地址';
$_LANG['form_data_source_url'] = '提交页面';
$_LANG['form_data_user'] = '提交会员';
$_LANG['form_data_user_guest'] = '游客';
$_LANG['form_data_if_read'] = '已读';
$_LANG['form_data_if_read_0'] = '未读';
$_LANG['form_data_if_read_1'] = '已读';
$_LANG['form_data_status'] = '状态';
$_LANG['form_data_status_0'] = '未处理';
$_LANG['form_data_status_1'] = '已处理';
$_LANG['form_data_created_at'] = '提交时间';
$_LANG['form_data_mark_all'] = '全部已处理';
$_LANG['form_data_mark_all_title'] = '全部标记为已处理';
$_LANG['form_data_mark_all_confirm'] = '确定把 d% 的全部提交标记为已读且已处理？';
$_LANG['form_data_mark_all_success'] = '已将 d% 条提交标记为已读且已处理';

// 字段类型（与 FormFieldSchema 一致）
$_LANG['form_type_input'] = '单行文本';
$_LANG['form_type_textarea'] = '多行文本';
$_LANG['form_type_select'] = '下拉选择';
$_LANG['form_type_radio'] = '单选按钮';
$_LANG['form_type_checkbox'] = '多选复选';
$_LANG['form_type_agree'] = '勾选协议';
$_LANG['form_type_switch'] = '开关';
$_LANG['form_type_number'] = '数字';
$_LANG['form_type_email'] = '邮箱';
$_LANG['form_type_tel'] = '电话/固话';
$_LANG['form_type_mobile'] = '手机号';
$_LANG['form_type_url'] = '网址';
$_LANG['form_type_date'] = '日期';
$_LANG['form_type_area'] = '省市区';
$_LANG['form_type_module_missing'] = '（模块未安装）';
$_LANG['form_type_image'] = '单图上传';
$_LANG['form_type_gallery'] = '多图上传';
$_LANG['form_type_file'] = '附件上传';

// 预置模板引导页
$_LANG['form_template_title'] = '添加表单';
$_LANG['form_template_intro'] = '从预置模板一键生成表单与整套字段，也可以先创建空白表单再逐个添加字段。';
$_LANG['form_template_blank'] = '创建空白表单';
$_LANG['form_template_blank_desc'] = '只建表单框架，名称、英文标记与提交行为稍后填写，字段在字段页逐个添加。';
$_LANG['form_template_blank_fields'] = '字段自由定义';
$_LANG['form_template_blank_btn'] = '立即创建';
$_LANG['form_template_use_btn'] = '一键使用';
$_LANG['form_template_field_badge'] = '含 d% 个字段';
$_LANG['form_template_created'] = '表单「d%」已创建，请继续添加字段';

// 预置模板（与 config/form.php 一致）
$_LANG['form_template_contact'] = '业务咨询';
$_LANG['form_template_quote'] = '产品询价';
$_LANG['form_template_feedback'] = '在线留言';
$_LANG['form_template_cooperation'] = '合作加盟';
$_LANG['form_template_resume'] = '简历投递';
