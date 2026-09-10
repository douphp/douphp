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

// 设置
$_LANG['top_add_ai'] = 'AI';
$_LANG['nav_ai'] = 'AI';

// AI创作应用
$_LANG['ai'] = 'AI应用';
$_LANG['ai_list'] = '应用列表';
$_LANG['ai_create'] = '添加应用';
$_LANG['ai_edit'] = '编辑应用';
$_LANG['ai_delete'] = '删除应用';
$_LANG['ai_add_succes'] = '添加应用成功';
$_LANG['ai_edit_succes'] = '编辑应用成功';
$_LANG['ai_select_empty'] = '没有选择任何应用';

// AI创作应用字段
$_LANG['ai_name'] = '应用名称';
$_LANG['ai_placement'] = '按钮位置';
$_LANG['ai_placement_assist'] = '字段旁边';
$_LANG['ai_placement_fill'] = '详情页';
$_LANG['ai_placement_batch'] = '列表页';
$_LANG['ai_placement_translate'] = '多语言框';
$_LANG['ai_placement_cue'] = '字段旁边：跟随每个带多语言按钮的字段，弹窗内按应用名并列多个提交钮；详情页：在添加页一键填充全表单；列表页：在列表页批量产出并直接入库；多语言框：在多语言弹窗内一键把当前字段译入对应语言。字段旁边与多语言框无需挂载模块和生成字段。';
$_LANG['ai_btn_assist'] = '辅助生成';
$_LANG['ai_task_type'] = '任务类型';
$_LANG['ai_task_type_polish'] = '字段润色';
$_LANG['ai_task_type_rewrite'] = '自动生成';
$_LANG['ai_task_type_image'] = '图像生成';
$_LANG['ai_task_type_banner'] = 'Banner生成';
$_LANG['ai_task_type_translate'] = '内容翻译';
$_LANG['ai_task_type_fill'] = '整表生成';
$_LANG['ai_task_type_batch'] = '批量内容';
$_LANG['ai_task_type_assist'] = '通用辅助';
$_LANG['ai_task_type_cue'] = '决定生成时使用哪套内置提示词（config/ai.php）；未指定任务的应用走「通用辅助」兜底';
$_LANG['ai_module'] = '挂载模块';
$_LANG['ai_module_select'] = '请选择模块';
$_LANG['ai_module_cue'] = '选择应用挂载的内容创作模块（仅按钮位置为详情页 / 列表页需要）';
$_LANG['ai_module_required'] = '请选择挂载模块';
$_LANG['ai_field'] = '生成字段';
$_LANG['ai_field_select_module_first'] = '请先选择模块';
$_LANG['ai_field_cue'] = '勾选 AI 要生成的字段';
$_LANG['ai_field_required'] = '请至少勾选一个生成字段';
$_LANG['ai_prompt_catalog_missing'] = '内置提示词配置缺失或损坏，请检查 config/ai.php';
$_LANG['ai_field_cue_translate'] = '勾选哪些字段，对应多语言弹窗才会显示翻译按钮；图片字段即使勾选也不会显示';
$_LANG['ai_advanced'] = '高级配置';
$_LANG['ai_model_select'] = '请选择模型';
$_LANG['ai_model_group_select'] = '请选择';
$_LANG['ai_model_default'] = '默认模型';
$_LANG['ai_model_cue'] = '该应用调用的AI模型';
$_LANG['ai_default_prompt'] = '系统提示词';
$_LANG['ai_default_prompt_cue'] = '可选。作为管理员个性化补充接在通用说明之后，与上文冲突时以这段为准（例如固定文风、必含要素等）';
$_LANG['ai_config'] = '应用配置';
$_LANG['ai_config_cue'] = 'JSON格式的应用配置，如：{"batch_min":1,"batch_max":20}；Banner应用可配 {"canvas_size":"1920x400","content_align":"center"}（canvas_size 画布宽x高，content_align 取值 left/center/right、标题文字水平对齐的默认值，弹窗可改选；文字一律垂直居中）';
$_LANG['ai_status'] = '状态';
$_LANG['ai_status_0'] = '禁用';
$_LANG['ai_status_1'] = '启用';
$_LANG['ai_status_toggle_cue'] = '点击启用/停用该应用';
$_LANG['ai_created_at'] = '创建时间';

// AI生成运行时
$_LANG['ai_generate'] = 'AI生成';
$_LANG['ai_generate_batch'] = 'AI批量生成';
$_LANG['ai_generate_count'] = '生成数量';
$_LANG['ai_generate_prompt'] = '生成要求';
$_LANG['ai_generate_prompt_cue'] = '描述要生成的内容主题、风格等要求';
$_LANG['ai_generate_app_not_found'] = 'AI应用不存在或已禁用';
$_LANG['ai_generate_failed'] = 'AI生成失败';
$_LANG['ai_generating'] = '正在生成，请稍候…';
$_LANG['ai_generating_btn'] = '生成中...';
$_LANG['ai_generating_translate'] = '正在生成翻译结果...';
$_LANG['ai_generate_invalid_json'] = 'AI返回内容解析失败，请重试';
$_LANG['ai_generate_count_invalid'] = '生成数量超出允许范围';
$_LANG['ai_generate_module_unsupported'] = '该模块暂不支持批量入库';
$_LANG['ai_generate_success'] = '生成成功';
$_LANG['ai_generate_batch_result'] = '批量生成完成：成功 %s 条，失败 %s 条';
$_LANG['ai_generate_empty'] = 'AI未返回任何内容，请调整提示词后重试';
$_LANG['ai_generate_prompt_field_cue'] = '可描述优化方向，留空则基于当前字段内容与表单上下文生成';
$_LANG['ai_prompt_show_system'] = '显示系统提示词';
$_LANG['ai_prompt_hide_system'] = '隐藏系统提示词';
$_LANG['ai_prompt_preview_failed'] = '提示词获取失败';
$_LANG['ai_translate_empty_source'] = '请先填写原文';
$_LANG['ai_lang_clear'] = '清空';

// 输出契约字段约束文案（拼入 JSON Schema description，供 LLM 理解）
$_LANG['ai_schema_hint_title'] = '简洁有吸引力的标题，纯文本';
$_LANG['ai_schema_hint_keywords'] = 'SEO关键词，3-5个，用英文逗号分隔';
$_LANG['ai_schema_hint_summary'] = '概要描述，纯文本，不含HTML标签';
$_LANG['ai_schema_hint_content'] = '正文内容，使用HTML段落标签组织，结构完整';
$_LANG['ai_schema_hint_integer'] = '整数';
$_LANG['ai_schema_hint_number'] = '数值';
$_LANG['ai_schema_hint_maxlength'] = '不超过%d个字符';
$_LANG['ai_import_item_invalid'] = '条目格式不正确';
$_LANG['ai_import_missing_field'] = '缺少必填字段';

// AI供应商
$_LANG['ai_provider_has_chats'] = '该供应商存在会话记录，无法删除';
$_LANG['ai_provider_has_logs'] = '该供应商存在使用日志，无法删除';
$_LANG['ai_provider_has_apps'] = '该供应商下的模型已被 AI 应用引用，无法删除';

// AI供应商字段
$_LANG['ai_provider_name'] = '供应商名称';
$_LANG['ai_provider_code'] = '供应商代码';
$_LANG['ai_provider_code_cue'] = '唯一标识，如：openai、anthropic、deepseek';
$_LANG['ai_provider_base_url'] = 'API基础地址';
$_LANG['ai_provider_base_url_cue'] = '如：https://api.openai.com/v1';
$_LANG['ai_provider_advanced'] = '高级配置';
$_LANG['ai_provider_config'] = '扩展配置';
$_LANG['ai_provider_config_cue'] = 'JSON格式的供应商扩展配置，如：{"supports_json_schema":true,"endpoints":{"text":"/chat/completions"}}（endpoints 可覆盖端点路径）';
$_LANG['ai_provider_created_at'] = '创建时间';
$_LANG['ai_provider_code_existed'] = '供应商代码已存在，请更换其它代码';
$_LANG['ai_provider_key_list'] = 'API 密钥';
$_LANG['ai_provider_key_list_cue'] = '保存供应商时一并创建/更新密钥；密钥留空表示不修改';
$_LANG['ai_provider_model_list_cue'] = '保存供应商时一并管理模型；模型名称与模型代码均填写才会保存';
$_LANG['ai_provider_status_cue'] = '停用后该供应商及其模型将无法被 AI 应用、生成任务调用';
$_LANG['ai_toggle_failed'] = '操作失败，请重试';
$_LANG['ai_provider_toggle_cue'] = '点击启用/停用该供应商';
$_LANG['ai_provider_enable_succes'] = '供应商已启用';
$_LANG['ai_provider_disable_succes'] = '供应商已停用';
$_LANG['ai_provider_batch_enable'] = '启用所选供应商';
$_LANG['ai_provider_batch_disable'] = '停用所选供应商';
$_LANG['ai_key_none'] = '未配置';
$_LANG['ai_provider_key_count_cue'] = '已配置的 API 密钥数量';
$_LANG['ai_provider_key_missing_cue'] = '尚未配置 API 密钥，点击进入编辑页添加';
$_LANG['ai_provider_setup_notice'] = '系统检测到还没有配置任何 API 密钥：没有密钥，AI 应用与生成任务将无法调用模型。请点击供应商名称进入编辑页，在「API 密钥」区添加密钥并保存。';

// AI模型
$_LANG['ai_model'] = 'AI模型';
$_LANG['ai_model_create'] = '添加模型';
$_LANG['ai_model_edit'] = '编辑模型';
$_LANG['ai_model_add_succes'] = '添加模型成功';
$_LANG['ai_model_edit_succes'] = '编辑模型成功';
$_LANG['ai_model_select_empty'] = '没有选择任何模型';
$_LANG['ai_model_add_inline'] = '添加模型';
$_LANG['ai_model_has_usage'] = '该模型存在使用记录，无法删除';
$_LANG['ai_model_has_chats'] = '该模型存在会话记录，无法删除';
$_LANG['ai_model_has_records'] = '该模型存在消息记录，无法删除';
$_LANG['ai_model_has_apps'] = '该模型被 AI 应用引用，无法删除';

// AI模型字段
$_LANG['ai_model_name'] = '模型名称';
$_LANG['ai_model_code'] = '模型代码';
$_LANG['ai_model_context_length'] = '上下文长度';
$_LANG['ai_model_max_tokens'] = '最大输出 token 数';
$_LANG['ai_model_code_existed'] = '模型代码已存在，请更换其它代码';

// AI密钥
$_LANG['ai_key'] = 'API密钥';
$_LANG['ai_key_list'] = '密钥列表';
$_LANG['ai_key_create'] = '添加密钥';
$_LANG['ai_key_edit'] = '编辑密钥';
$_LANG['ai_key_delete'] = '删除密钥';
$_LANG['ai_key_add_succes'] = '添加密钥成功';
$_LANG['ai_key_edit_succes'] = '编辑密钥成功';
$_LANG['ai_key_select_empty'] = '没有选择任何密钥';
$_LANG['ai_key_has_usage'] = '该密钥存在使用记录，无法删除';

// AI密钥字段
$_LANG['ai_key_provider'] = '所属供应商';
$_LANG['ai_key_api_key'] = 'API密钥';
$_LANG['ai_key_api_key_cue'] = '密钥以明文存储，请确保数据库与服务器访问安全';
$_LANG['ai_key_alias'] = '密钥别名';
$_LANG['ai_key_alias_cue'] = '仅用于区分多个KEY，无实际作用，可以随意输入';
$_LANG['ai_key_expires_at'] = '过期时间';
$_LANG['ai_key_expires_at_cue'] = '不填表示永久有效';
$_LANG['ai_key_last_used_at'] = '最后使用时间';
$_LANG['ai_key_failure_count'] = '失败次数';
$_LANG['ai_key_config'] = '扩展配置';
$_LANG['ai_key_config_cue'] = 'JSON格式的扩展配置，如百度 client_secret 会自动写入';
$_LANG['ai_key_client_secret'] = '百度 Secret Key';
$_LANG['ai_key_client_secret_cue'] = '百度文心 access_token 换发所需，保存后自动写入密钥扩展配置';
$_LANG['ai_key_created_at'] = '创建时间';
$_LANG['ai_key_quota_status'] = '配额状态';
$_LANG['ai_key_add_inline'] = '添加密钥';
$_LANG['ai_key_advanced_options'] = '高级选项';
$_LANG['ai_key_save'] = '保存';
$_LANG['ai_key_cancel'] = '取消';
$_LANG['ai_key_reset'] = '重置';
$_LANG['ai_key_reset_succes'] = '密钥失败计数已重置';
$_LANG['ai_key_reset_confirm'] = '确定重置该密钥的失败计数？';
$_LANG['ai_key_api_key_keep'] = '已配置-输入新值可替换';
$_LANG['ai_key_api_key_required'] = '请至少添加一把有效的API密钥';
$_LANG['ai_key_has_chats'] = '该密钥存在会话记录，无法删除';
$_LANG['ai_key_id_fallback'] = '密钥ID:%s';

// 使用日志
$_LANG['ai_log'] = '使用日志';
$_LANG['ai_log_list'] = '日志列表';
$_LANG['ai_log_show'] = '日志详情';
$_LANG['ai_log_delete'] = '删除日志';
$_LANG['ai_log_select_empty'] = '没有选择任何日志';

// 使用日志字段
$_LANG['ai_log_admin'] = '管理员';
$_LANG['ai_log_admin_id'] = '管理员ID';
$_LANG['ai_log_app'] = '应用';
$_LANG['ai_log_provider'] = '供应商';
$_LANG['ai_log_model'] = '模型';
$_LANG['ai_log_key'] = '密钥';
$_LANG['ai_log_request_id'] = '请求ID';
$_LANG['ai_log_prompt_tokens'] = '输入Tokens';
$_LANG['ai_log_completion_tokens'] = '输出Tokens';
$_LANG['ai_log_total_tokens'] = '总Tokens';
$_LANG['ai_log_duration'] = '耗时';
$_LANG['ai_log_duration_ms'] = '毫秒';
$_LANG['ai_log_status_code'] = '状态码';
$_LANG['ai_log_has_error'] = '状态';
$_LANG['ai_log_has_error_0'] = '成功';
$_LANG['ai_log_has_error_1'] = '失败';
$_LANG['ai_log_error_message'] = '错误信息';
$_LANG['ai_log_endpoint'] = 'API端点';
$_LANG['ai_log_metadata'] = '元数据';
$_LANG['ai_log_prompt_content'] = '最终提示词';
$_LANG['ai_log_response_content'] = 'AI返回内容';
$_LANG['ai_log_ip_address'] = 'IP地址';
$_LANG['ai_log_created_at'] = '请求时间';
$_LANG['ai_log_basic_info'] = '基本信息';
$_LANG['ai_log_token_info'] = 'Token信息';
$_LANG['ai_log_request_info'] = '请求信息';

// AI 异步任务
$_LANG['ai_task'] = '异步任务';
$_LANG['ai_task_list'] = '任务列表';
$_LANG['ai_task_delete'] = '删除任务';
$_LANG['ai_task_submit_failed'] = '任务提交失败';

// AI 异步任务字段
$_LANG['ai_task_app'] = '应用';
$_LANG['ai_task_provider'] = '供应商';
$_LANG['ai_task_model'] = '模型';
$_LANG['ai_async_type'] = '任务类型';
$_LANG['ai_async_type_image'] = '图像';
$_LANG['ai_async_type_video'] = '视频';
$_LANG['ai_async_type_audio'] = '音频';
$_LANG['ai_async_type_all'] = '全部类型';
$_LANG['ai_task_status'] = '状态';
$_LANG['ai_task_status_pending'] = '等待中';
$_LANG['ai_task_status_running'] = '生成中';
$_LANG['ai_task_status_succeeded'] = '已完成';
$_LANG['ai_task_status_failed'] = '失败';
$_LANG['ai_task_status_timeout'] = '已超时';
$_LANG['ai_task_status_all'] = '全部状态';
$_LANG['ai_task_created_at'] = '提交时间';
$_LANG['ai_task_updated_at'] = '更新时间';
$_LANG['ai_task_result'] = '生成结果';
$_LANG['ai_task_result_expired'] = '结果已过期，请重新生成';
$_LANG['ai_task_polling'] = '正在生成，请稍候（长视频可能需要几分钟）…';
$_LANG['ai_task_poll_network'] = '网络不稳定，任务仍在后台执行，可稍后在「异步任务」列表查看结果';
$_LANG['ai_task_result_title'] = '生成结果';
$_LANG['ai_task_copy'] = '复制链接';
$_LANG['ai_task_copy_done'] = '链接已复制';
$_LANG['ai_task_view'] = '查看原文件';
$_LANG['ai_task_close'] = '关闭';
$_LANG['ai_task_not_succeeded'] = '任务尚未完成，请稍后再试';
$_LANG['ai_image_generate'] = 'AI 生成图';
$_LANG['ai_image_ratio_custom'] = '自定义';
$_LANG['ai_align_left'] = '左对齐';
$_LANG['ai_align_right'] = '右对齐';
$_LANG['ai_align_center'] = '居中对齐';
$_LANG['ai_layout_title'] = '构图与布局';
$_LANG['ai_size_title'] = '尺寸';

// Banner 弹窗（幻灯辅助生成）
$_LANG['ai_banner_material'] = '图片素材';
$_LANG['ai_banner_material_cue'] = '可选：选产品主图或上传本地图片，勾选后仅抠出素材主体';
$_LANG['ai_banner_material_mode_subject'] = '抠出主体';
$_LANG['ai_banner_material_product'] = '选择产品主图';
$_LANG['ai_banner_material_upload'] = '本地上传';
$_LANG['ai_banner_material_search'] = '输入产品名称，自动搜索';
$_LANG['ai_banner_material_empty'] = '没有可用的产品主图';
$_LANG['ai_banner_material_max'] = '最多选择 4 张素材';
$_LANG['ai_banner_material_invalid'] = '仅支持 5MB 以内的图片文件';
$_LANG['ai_banner_title'] = '主标题';
$_LANG['ai_banner_title_cue'] = '画进 banner 的大字标题，如公司口号';
$_LANG['ai_banner_subtitle'] = '副标题';
$_LANG['ai_banner_subtitle_cue'] = '画进 banner 的小字说明，如活动信息';
$_LANG['ai_banner_style'] = '风格';
$_LANG['ai_banner_style_business'] = '商务简约';
$_LANG['ai_banner_style_tech'] = '科技蓝调';
$_LANG['ai_banner_style_vibrant'] = '活力渐变';
$_LANG['ai_banner_style_promo'] = '电商促销';
$_LANG['ai_banner_style_fresh'] = '自然清新';
$_LANG['ai_banner_style_industry'] = '工业质感';
$_LANG['ai_banner_style_none'] = '不指定';
$_LANG['ai_banner_prompt_cue'] = '补充画面细节，如：以车间设备为主体、蓝色科技光效、右侧留白放文字；留空则按标题与风格自动生成';
$_LANG['ai_image_use'] = '使用此图';
$_LANG['ai_image_crop'] = '裁剪';
$_LANG['ai_image_regenerate'] = '重新生成';
$_LANG['ai_image_crop_loading'] = '正在打开裁剪…';
$_LANG['ai_image_loading'] = '获取中...';
$_LANG['ai_image_fetch_failed'] = '获取图片失败';
$_LANG['ai_image_field_missing'] = '未找到目标图片字段';

// 核心层错误 / JSON 指令（admin 与前台 ai.lang.php 同步）
$_LANG['ai_no_model_config'] = '无可用的AI模型配置';
$_LANG['ai_api_credential_failed'] = 'API访问凭证获取失败，请检查密钥配置';
$_LANG['ai_http_request_failed'] = 'HTTP请求失败';
$_LANG['ai_http_error_code'] = 'HTTP错误代码: %s';
$_LANG['ai_api_request_failed'] = 'API请求失败: %s';
$_LANG['ai_json_parse_failed'] = 'JSON解析失败: %s';
$_LANG['ai_api_unknown_error'] = '未知API错误';
$_LANG['ai_api_error'] = 'API错误: %s';
$_LANG['ai_api_response_invalid'] = 'API返回格式异常';
$_LANG['ai_json_schema_instruction'] = '你必须只输出一个合法的 JSON（不带 Markdown 代码块标记、不带任何解释文字），严格符合以下 JSON Schema：';
$_LANG['ai_json_content_invalid'] = 'AI返回内容不是合法JSON';
$_LANG['ai_stream_unsupported'] = '该模型不支持流式输出，请在应用配置中更换模型';
$_LANG['ai_curl_error'] = 'cURL错误(%s): %s';
$_LANG['ai_api_base_url_hint'] = ' (请检查供应商的API基础地址是否正确，如DeepSeek应为 https://api.deepseek.com/v1)';
$_LANG['ai_task_table_missing'] = 'AI 任务数据表不存在，请先执行升级 SQL 创建 ai_task 表后再使用';
$_LANG['ai_task_timeout'] = '任务执行超时';
$_LANG['ai_task_async_unsupported'] = '该模型不支持异步任务模式';
$_LANG['ai_task_no_provider_id'] = '供应商未返回任务ID';
$_LANG['ai_task_submit_no_id'] = '任务提交失败：供应商未返回任务ID';
$_LANG['ai_task_not_found'] = '任务不存在';
$_LANG['ai_image_prompt_empty'] = '图片生成描述不能为空';
$_LANG['ai_image_unsupported'] = '该模型不支持图片生成';
$_LANG['ai_task_config_unavailable'] = '模型配置不可用';
$_LANG['ai_task_provider_no_async'] = '供应商不支持异步任务';
$_LANG['ai_task_provider_failed'] = '供应商返回任务失败';
$_LANG['ai_task_query_failed'] = '任务查询失败: %s';
$_LANG['ai_task_submit_failed_detail'] = '任务提交失败: %s';
$_LANG['ai_task_failed'] = '任务失败';
$_LANG['ai_error_code'] = '错误码 %s';
$_LANG['ai_task_placement_mismatch'] = '应用用途与任务类型不匹配';
$_LANG['ai_model_task_mismatch'] = '所选模型不支持当前应用用途，请更换模型';
$_LANG['ai_generate_input_too_large'] = '提交的表单上下文或图片素材过多';
$_LANG['ai_generate_duplicate_batch'] = '相同的批量生成请求正在处理或刚刚完成，请稍后再试';
$_LANG['ai_nested_input_invalid'] = '密钥或模型参数格式不正确';
