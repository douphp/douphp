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

// 核心層錯誤 / JSON 指令（前台聊天與後台 admin/ai.lang.php 同步）
$_LANG['ai_no_model_config'] = '無可用的AI模型設定';
$_LANG['ai_api_credential_failed'] = 'API存取憑證取得失敗，請檢查金鑰設定';
$_LANG['ai_http_request_failed'] = 'HTTP請求失敗';
$_LANG['ai_http_error_code'] = 'HTTP錯誤代碼: %s';
$_LANG['ai_api_request_failed'] = 'API請求失敗: %s';
$_LANG['ai_json_parse_failed'] = 'JSON解析失敗: %s';
$_LANG['ai_api_unknown_error'] = '未知API錯誤';
$_LANG['ai_api_error'] = 'API錯誤: %s';
$_LANG['ai_api_response_invalid'] = 'API返回格式異常';
$_LANG['ai_json_schema_instruction'] = '你必須只輸出一個合法的 JSON（不帶 Markdown 程式碼區塊標記、不帶任何解釋文字），嚴格符合以下 JSON Schema：';
$_LANG['ai_json_content_invalid'] = 'AI返回內容不是合法JSON';
$_LANG['ai_stream_unsupported'] = '該模型不支援串流輸出，請在應用設定中更換模型';
$_LANG['ai_curl_error'] = 'cURL錯誤(%s): %s';
$_LANG['ai_api_base_url_hint'] = ' (請檢查供應商的API基礎地址是否正確，如DeepSeek應為 https://api.deepseek.com/v1)';
$_LANG['ai_task_table_missing'] = 'AI 任務資料表不存在，請先執行升級 SQL 建立 ai_task 表後再使用';
$_LANG['ai_task_timeout'] = '任務執行逾時';
$_LANG['ai_task_async_unsupported'] = '該模型不支援非同步任務模式';
$_LANG['ai_task_no_provider_id'] = '供應商未返回任務ID';
$_LANG['ai_task_submit_no_id'] = '任務提交失敗：供應商未返回任務ID';
$_LANG['ai_task_not_found'] = '任務不存在';
$_LANG['ai_image_prompt_empty'] = '圖片生成描述不能為空';
$_LANG['ai_image_unsupported'] = '該模型不支援圖片生成';
$_LANG['ai_task_config_unavailable'] = '模型設定不可用';
$_LANG['ai_task_provider_no_async'] = '供應商不支援非同步任務';
$_LANG['ai_task_provider_failed'] = '供應商返回任務失敗';
$_LANG['ai_task_query_failed'] = '任務查詢失敗: %s';
$_LANG['ai_task_submit_failed_detail'] = '任務提交失敗: %s';
$_LANG['ai_task_failed'] = '任務失敗';
$_LANG['ai_error_code'] = '錯誤碼 %s';
