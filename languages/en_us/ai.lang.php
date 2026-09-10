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

// Core AI errors / JSON instruction (front chat; mirrored in admin/ai.lang.php)
$_LANG['ai_no_model_config'] = 'No available AI model configuration';
$_LANG['ai_api_credential_failed'] = 'Failed to obtain API credentials, please check the key configuration';
$_LANG['ai_http_request_failed'] = 'HTTP request failed';
$_LANG['ai_http_error_code'] = 'HTTP error code: %s';
$_LANG['ai_api_request_failed'] = 'API request failed: %s';
$_LANG['ai_json_parse_failed'] = 'JSON parse failed: %s';
$_LANG['ai_api_unknown_error'] = 'Unknown API error';
$_LANG['ai_api_error'] = 'API error: %s';
$_LANG['ai_api_response_invalid'] = 'Unexpected API response format';
$_LANG['ai_json_schema_instruction'] = 'You must output only a valid JSON object (no Markdown code fences, no explanations), strictly matching the following JSON Schema:';
$_LANG['ai_json_content_invalid'] = 'AI response is not valid JSON';
$_LANG['ai_stream_unsupported'] = 'This model does not support streaming. Please change the model in the application settings';
$_LANG['ai_curl_error'] = 'cURL error (%s): %s';
$_LANG['ai_api_base_url_hint'] = ' (Please check the provider API base URL, e.g. DeepSeek should be https://api.deepseek.com/v1)';
$_LANG['ai_task_table_missing'] = 'The AI task table does not exist. Please run the upgrade SQL to create ai_task before using this feature';
$_LANG['ai_task_timeout'] = 'Task timed out';
$_LANG['ai_task_async_unsupported'] = 'This model does not support async tasks';
$_LANG['ai_task_no_provider_id'] = 'The provider did not return a task ID';
$_LANG['ai_task_submit_no_id'] = 'Task submission failed: the provider did not return a task ID';
$_LANG['ai_task_not_found'] = 'Task not found';
$_LANG['ai_image_prompt_empty'] = 'Image generation prompt cannot be empty';
$_LANG['ai_image_unsupported'] = 'This model does not support image generation';
$_LANG['ai_task_config_unavailable'] = 'Model configuration is unavailable';
$_LANG['ai_task_provider_no_async'] = 'The provider does not support async tasks';
$_LANG['ai_task_provider_failed'] = 'The provider reported that the task failed';
$_LANG['ai_task_query_failed'] = 'Task query failed: %s';
$_LANG['ai_task_submit_failed_detail'] = 'Task submission failed: %s';
$_LANG['ai_task_failed'] = 'Task failed';
$_LANG['ai_error_code'] = 'Error code %s';
