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

// 核心层错误 / JSON 指令（前台聊天与后台 admin/ai.lang.php 同步）
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
