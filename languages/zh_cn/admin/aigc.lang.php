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

// 导航
$_LANG['nav_aigc'] = 'AI应用';

// 会员 AI 应用
$_LANG['aigc'] = 'AI应用';
$_LANG['aigc_list'] = '应用列表';
$_LANG['aigc_create'] = '添加应用';
$_LANG['aigc_edit'] = '编辑应用';
$_LANG['aigc_add_succes'] = '添加应用成功';
$_LANG['aigc_edit_succes'] = '编辑应用成功';
$_LANG['aigc_select_empty'] = '没有选择任何应用';
$_LANG['aigc_delete_has_data'] = '应用「d%」已有会话或任务记录，不能删除；请先清理相关数据';
$_LANG['aigc_user_not_found'] = '未找到该会员，已显示空结果';

// 应用字段
$_LANG['aigc_name'] = '应用名称';
$_LANG['aigc_slug'] = '标识';
$_LANG['aigc_slug_cue'] = '应用唯一标识，仅限字母、数字、下划线和中划线';
$_LANG['aigc_type'] = '应用类型';
$_LANG['aigc_type_chat'] = '文本对话';
$_LANG['aigc_type_image'] = '图片生成';
$_LANG['aigc_type_video'] = '视频生成';
$_LANG['aigc_type_audio'] = '音频生成';
$_LANG['aigc_type_ppt'] = 'PPT生成';
$_LANG['aigc_type_excel'] = 'Excel生成';
$_LANG['aigc_type_cue'] = '应用类型决定会员端可用的生成能力；文本对话支持多轮上下文与推荐问题。';

// 应用表单字段
$_LANG['aigc_field'] = '表单字段';
$_LANG['aigc_field_manage'] = '表单字段管理';
$_LANG['aigc_field_list'] = '字段列表';
$_LANG['aigc_field_manage_link'] = '管理字段';
$_LANG['aigc_field_create'] = '添加字段';
$_LANG['aigc_field_edit'] = '编辑字段';
$_LANG['aigc_field_add_succes'] = '添加字段成功';
$_LANG['aigc_field_edit_succes'] = '编辑字段成功';
$_LANG['aigc_field_app_missing'] = '应用不存在';
$_LANG['aigc_field_slug_exists'] = '同一应用内字段标识不能重复';
$_LANG['aigc_field_empty'] = '暂无字段，会员端将使用默认提示词输入框';
$_LANG['aigc_field_cue'] = '为应用配置会员端填写表单；字段值会作为生成参数传给模型';
$_LANG['aigc_field_form_cue'] = '配置应用表单字段';
$_LANG['aigc_field_name'] = '字段名称';
$_LANG['aigc_field_slug'] = '字段标识';
$_LANG['aigc_field_slug_cue'] = '小写字母开头，仅限字母、数字、下划线；尺寸/数量/时长/分辨率/音色/语速/页数/工作表数/风格等标识会直接作为生成参数';
$_LANG['aigc_field_type'] = '字段类型';
$_LANG['aigc_field_type_input'] = '单行文本';
$_LANG['aigc_field_type_textarea'] = '多行文本';
$_LANG['aigc_field_type_number'] = '数字';
$_LANG['aigc_field_type_select'] = '下拉选择';
$_LANG['aigc_field_type_radio'] = '单选';
$_LANG['aigc_field_type_checkbox'] = '多选';
$_LANG['aigc_field_option'] = '选项';
$_LANG['aigc_field_option_cue'] = '每行一个选项，格式：值|显示名；只写显示名时值同显示名。仅下拉/单选/多选使用';
$_LANG['aigc_field_placeholder'] = '占位提示';
$_LANG['aigc_field_default'] = '默认值';
$_LANG['aigc_field_tip'] = '字段说明';
$_LANG['aigc_field_must_fill'] = '填写要求';
$_LANG['aigc_field_must_fill_0'] = '可选';
$_LANG['aigc_field_must_fill_1'] = '必填';
$_LANG['aigc_field_width'] = '宽度';
$_LANG['aigc_field_width_cue'] = '1-12 栅格宽度，12 为整行';
$_LANG['aigc_field_sort'] = '排序';
$_LANG['aigc_field_status'] = '状态';
$_LANG['aigc_description'] = '应用描述';
$_LANG['aigc_icon'] = '图标';
$_LANG['aigc_cover_image'] = '封面图';
$_LANG['aigc_model'] = '可用模型';
$_LANG['aigc_model_cue'] = '选择该应用可使用的AI模型（必选，至少一个）';
$_LANG['aigc_model_available'] = '可选模型';
$_LANG['aigc_model_selected'] = '已选模型';
$_LANG['aigc_default_prompt'] = '系统提示词';
$_LANG['aigc_default_prompt_cue'] = '作为 system 角色注入对话上下文，用于设定 AI 的角色与行为';
$_LANG['aigc_feedback_up'] = '有用（赞）';
$_LANG['aigc_feedback_down'] = '无用（踩）';
$_LANG['aigc_suggested_questions'] = '推荐问题';
$_LANG['aigc_suggested_questions_cue'] = '每行一条，最多 6 条；仅文本对话类型使用，展示在会话欢迎区。';
$_LANG['aigc_config'] = '扩展配置';
$_LANG['aigc_config_cue'] = 'JSON 格式的扩展配置，如温度、最大输出等参数';
$_LANG['aigc_fee_mode'] = '收费模式';
$_LANG['aigc_fee_mode_cue'] = '免费应用不消耗会员订阅配额，但受每日限额约束；付费应用按订阅配额扣减';
$_LANG['aigc_is_free'] = '收费模式';
$_LANG['aigc_is_free_0'] = '付费';
$_LANG['aigc_is_free_1'] = '免费';
$_LANG['aigc_free_limit'] = '免费每日限额';
$_LANG['aigc_free_limit_type_requests'] = '按次数';
$_LANG['aigc_free_limit_type_tokens'] = '按tokens';
$_LANG['aigc_free_limit_cue'] = '每个会员每天可用量，0 表示不限制，按自然日重置';
$_LANG['aigc_free_zero_warning'] = '免费应用每日限额为 0（不限），所有会员可无限使用，请确认成本风险。';
$_LANG['aigc_is_public'] = '是否公开';
$_LANG['aigc_is_public_1'] = '公开';
$_LANG['aigc_is_public_0'] = '隐藏';
$_LANG['aigc_sort'] = '排序';
$_LANG['aigc_status'] = '状态';
$_LANG['aigc_status_1'] = '启用';
$_LANG['aigc_status_0'] = '停用';

// 会话
$_LANG['aigc_session'] = '会话管理';
$_LANG['aigc_session_list'] = '会话列表';
$_LANG['aigc_session_show'] = '会话详情';
$_LANG['aigc_session_select_empty'] = '没有选择任何会话';
$_LANG['aigc_session_app'] = '应用';
$_LANG['aigc_session_title'] = '会话标题';
$_LANG['aigc_session_user'] = '会员';
$_LANG['aigc_session_username'] = '会员名';
$_LANG['aigc_session_keyword'] = '标题关键字';
$_LANG['aigc_session_status'] = '状态';
$_LANG['aigc_session_status_active'] = '进行中';
$_LANG['aigc_session_status_closed'] = '已关闭';
$_LANG['aigc_session_message_count'] = '消息数';
$_LANG['aigc_session_last_active'] = '最近活动';
$_LANG['aigc_session_close'] = '关闭会话';
$_LANG['aigc_session_close_succes'] = '会话已关闭';
$_LANG['aigc_session_load_earlier'] = '加载更早消息';
$_LANG['aigc_session_back_latest'] = '返回最新';

// 消息角色
$_LANG['aigc_message_role_user'] = '会员';
$_LANG['aigc_message_role_ai'] = 'AI';
$_LANG['aigc_message_role_system'] = '系统';

// 套餐
$_LANG['aigc_package'] = 'AI套餐';
$_LANG['aigc_package_create'] = '添加套餐';
$_LANG['aigc_package_edit'] = '编辑套餐';
$_LANG['aigc_package_add_succes'] = '添加套餐成功';
$_LANG['aigc_package_edit_succes'] = '编辑套餐成功';
$_LANG['aigc_package_select_empty'] = '没有选择任何套餐';
$_LANG['aigc_package_has_subscription'] = '该套餐已有订阅记录，不能删除';
$_LANG['aigc_package_name'] = '套餐名称';
$_LANG['aigc_package_day'] = '有效期';
$_LANG['aigc_package_day_unit'] = '天';
$_LANG['aigc_package_day_cue'] = '购买后的有效天数';
$_LANG['aigc_package_tokens_limit'] = 'Tokens额度';
$_LANG['aigc_package_tokens_limit_cue'] = '套餐包含的tokens总量，0表示不限制';
$_LANG['aigc_package_requests_limit'] = '请求次数';
$_LANG['aigc_package_requests_limit_cue'] = '套餐包含的请求次数，0表示不限制';
$_LANG['aigc_package_price'] = '价格';
$_LANG['aigc_package_price_cue'] = '套餐售价，单位元';
$_LANG['aigc_package_content'] = '套餐说明';
$_LANG['aigc_package_content_cue'] = '展示给会员的套餐说明，支持HTML';
$_LANG['aigc_package_unlimited'] = '不限制';
$_LANG['aigc_package_status_1'] = '上架';
$_LANG['aigc_package_status_0'] = '下架';

// 订阅
$_LANG['aigc_subscription'] = '订阅记录';
$_LANG['aigc_subscription_list'] = '订阅列表';
$_LANG['aigc_subscription_show'] = '订阅详情';
$_LANG['aigc_subscription_select_empty'] = '没有选择任何订阅记录';
$_LANG['aigc_subscription_user'] = '会员';
$_LANG['aigc_subscription_package'] = '套餐';
$_LANG['aigc_subscription_order_sn'] = '订单号';
$_LANG['aigc_subscription_price'] = '价格';
$_LANG['aigc_subscription_usage'] = '用量';
$_LANG['aigc_subscription_requests'] = '请求';
$_LANG['aigc_subscription_order_status'] = '订单状态';
$_LANG['aigc_subscription_order_status_pending'] = '待付款';
$_LANG['aigc_subscription_order_status_awaiting_confirmation'] = '待确认';
$_LANG['aigc_subscription_order_status_paid'] = '已付款';
$_LANG['aigc_subscription_order_status_completed'] = '已完成';
$_LANG['aigc_subscription_order_status_cancelled'] = '已取消';
$_LANG['aigc_subscription_sale_price_type_discount'] = '折扣';
$_LANG['aigc_subscription_sale_price_type_fixed'] = '固定价';
$_LANG['aigc_subscription_created_at'] = '下单时间';
$_LANG['aigc_subscription_start_at'] = '生效时间';
$_LANG['aigc_subscription_end_at'] = '到期时间';
$_LANG['aigc_subscription_expired'] = '已过期';
$_LANG['aigc_subscription_valid'] = '有效';
$_LANG['aigc_subscription_basic_info'] = '基本信息';
$_LANG['aigc_subscription_quota_info'] = '额度信息';
$_LANG['aigc_subscription_time_info'] = '时间信息';
$_LANG['aigc_subscription_tokens_limit'] = 'Tokens额度';
$_LANG['aigc_subscription_tokens_used'] = '已用Tokens';
$_LANG['aigc_subscription_tokens_remaining'] = '剩余Tokens';
$_LANG['aigc_subscription_tokens_percent'] = 'Tokens使用率';
$_LANG['aigc_subscription_requests_limit'] = '请求次数';
$_LANG['aigc_subscription_requests_used'] = '已用次数';
$_LANG['aigc_subscription_requests_remaining'] = '剩余次数';
$_LANG['aigc_subscription_requests_percent'] = '次数使用率';

// 配额
$_LANG['aigc_quota'] = '会员配额';
$_LANG['aigc_quota_create'] = '添加配额';
$_LANG['aigc_quota_edit'] = '编辑配额';
$_LANG['aigc_quota_add_succes'] = '添加配额成功';
$_LANG['aigc_quota_edit_succes'] = '编辑配额成功';
$_LANG['aigc_quota_select_empty'] = '没有选择任何配额';
$_LANG['aigc_quota_exists'] = '该会员已存在同类型配额';
$_LANG['aigc_quota_user_not_found'] = '会员不存在';
$_LANG['aigc_quota_user'] = '会员';
$_LANG['aigc_quota_username'] = '会员名';
$_LANG['aigc_quota_username_cue'] = '输入会员用户名或邮箱';
$_LANG['aigc_quota_type'] = '配额类型';
$_LANG['aigc_quota_type_tokens'] = 'Tokens';
$_LANG['aigc_quota_type_requests'] = '请求次数';
$_LANG['aigc_quota_type_amount'] = '金额';
$_LANG['aigc_quota_total_limit'] = '总额度';
$_LANG['aigc_quota_total_limit_cue'] = '0表示不限制';
$_LANG['aigc_quota_used_amount'] = '已用量';
$_LANG['aigc_quota_usage_percent'] = '使用率';
$_LANG['aigc_quota_unlimited'] = '不限制';
$_LANG['aigc_quota_reset'] = '重置已用量';
$_LANG['aigc_quota_reset_succes'] = '重置已用量成功';
$_LANG['aigc_quota_reset_cycle'] = '重置周期';
$_LANG['aigc_quota_reset_cycle_never'] = '不重置';
$_LANG['aigc_quota_reset_cycle_daily'] = '每日';
$_LANG['aigc_quota_reset_cycle_weekly'] = '每周';
$_LANG['aigc_quota_reset_cycle_monthly'] = '每月';
$_LANG['aigc_quota_reset_cycle_yearly'] = '每年';
$_LANG['aigc_quota_expires_at'] = '过期时间';
$_LANG['aigc_quota_expires_at_cue'] = '留空表示永久有效';

// 用量日志
$_LANG['aigc_usage_log'] = '用量日志';
$_LANG['aigc_usage_log_list'] = '日志列表';
$_LANG['aigc_usage_log_show'] = '日志详情';
$_LANG['aigc_usage_log_select_empty'] = '没有选择任何日志';
$_LANG['aigc_usage_log_user'] = '会员';
$_LANG['aigc_usage_log_app'] = '应用';
$_LANG['aigc_usage_log_session'] = '会话ID';
$_LANG['aigc_usage_log_provider'] = '供应商';
$_LANG['aigc_usage_log_model'] = '模型';
$_LANG['aigc_usage_log_request_id'] = '请求ID';
$_LANG['aigc_usage_log_tokens'] = 'Tokens';
$_LANG['aigc_usage_log_prompt_tokens'] = '输入Tokens';
$_LANG['aigc_usage_log_completion_tokens'] = '输出Tokens';
$_LANG['aigc_usage_log_total_tokens'] = '总Tokens';
$_LANG['aigc_usage_log_duration'] = '耗时';
$_LANG['aigc_usage_log_status_code'] = '状态码';
$_LANG['aigc_usage_log_has_error'] = '状态';
$_LANG['aigc_usage_log_has_error_0'] = '成功';
$_LANG['aigc_usage_log_has_error_1'] = '失败';
$_LANG['aigc_usage_log_error_message'] = '错误信息';
$_LANG['aigc_usage_log_endpoint'] = 'API端点';
$_LANG['aigc_usage_log_ip'] = 'IP地址';
$_LANG['aigc_usage_log_metadata'] = '元数据';
$_LANG['aigc_usage_log_created_at'] = '请求时间';
$_LANG['aigc_usage_log_basic_info'] = '基本信息';
$_LANG['aigc_usage_log_token_info'] = 'Token信息';

// 异步任务
$_LANG['aigc_task'] = '生成任务';
$_LANG['aigc_task_show'] = '任务详情';
$_LANG['aigc_task_not_terminal'] = '任务尚未结束，不能删除';
$_LANG['aigc_task_basic_info'] = '基本信息';
$_LANG['aigc_task_user'] = '会员';
$_LANG['aigc_task_app'] = '应用';
$_LANG['aigc_task_provider'] = '供应商';
$_LANG['aigc_task_model'] = '模型';
$_LANG['aigc_task_type'] = '任务类型';
$_LANG['aigc_task_status'] = '任务状态';
$_LANG['aigc_task_result'] = '结果';
$_LANG['aigc_task_error'] = '错误信息';
$_LANG['aigc_task_request_payload'] = '请求参数';
$_LANG['aigc_task_created_at'] = '提交时间';
$_LANG['aigc_task_updated_at'] = '更新时间';
$_LANG['aigc_task_type_all'] = '全部类型';
$_LANG['aigc_task_type_image'] = '图片生成';
$_LANG['aigc_task_type_video'] = '视频生成';
$_LANG['aigc_task_type_audio'] = '音频生成';
$_LANG['aigc_task_type_ppt'] = 'PPT生成';
$_LANG['aigc_task_type_excel'] = 'Excel生成';
$_LANG['aigc_task_status_all'] = '全部状态';
$_LANG['aigc_task_status_pending'] = '待处理';
$_LANG['aigc_task_status_running'] = '执行中';
$_LANG['aigc_task_status_succeeded'] = '已完成';
$_LANG['aigc_task_status_failed'] = '失败';
$_LANG['aigc_task_status_timeout'] = '超时';
