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

// 系統設定
$_LANG['top_add_ai'] = 'AI';
$_LANG['nav_ai'] = 'AI';

// AI 創作應用
$_LANG['ai'] = 'AI 應用';
$_LANG['ai_list'] = '應用列表';
$_LANG['ai_create'] = '新增應用';
$_LANG['ai_edit'] = '編輯應用';
$_LANG['ai_delete'] = '刪除應用';
$_LANG['ai_add_succes'] = '新增應用成功';
$_LANG['ai_edit_succes'] = '編輯應用成功';
$_LANG['ai_select_empty'] = '沒有選擇任何應用';

// AI 創作應用欄位
$_LANG['ai_name'] = '應用名稱';
$_LANG['ai_placement'] = '按鈕位置';
$_LANG['ai_placement_assist'] = '欄位旁邊';
$_LANG['ai_placement_fill'] = '詳情頁';
$_LANG['ai_placement_batch'] = '列表頁';
$_LANG['ai_placement_translate'] = '多語言框';
$_LANG['ai_placement_cue'] = '欄位旁邊：跟隨每個帶多語言按鈕的欄位，彈窗內按應用名並列多個提交鈕；詳情頁：在新增頁一鍵填充全表單；列表頁：在列表頁批次產出並直接入庫；多語言框：在多語言彈窗內一鍵把當前欄位譯入對應語言。欄位旁邊與多語言框無需掛載模組和產生欄位。';
$_LANG['ai_btn_assist'] = '輔助生成';
$_LANG['ai_task_type'] = '任務類型';
$_LANG['ai_task_type_polish'] = '欄位潤色';
$_LANG['ai_task_type_rewrite'] = '自動生成';
$_LANG['ai_task_type_image'] = '圖像生成';
$_LANG['ai_task_type_banner'] = 'Banner 生成';
$_LANG['ai_task_type_translate'] = '內容翻譯';
$_LANG['ai_task_type_fill'] = '整表生成';
$_LANG['ai_task_type_batch'] = '批量內容';
$_LANG['ai_task_type_assist'] = '通用輔助';
$_LANG['ai_task_type_cue'] = '決定產生時使用哪套內建提示詞（config/ai.php）；未指定任務的應用走「通用輔助」兜底';
$_LANG['ai_module'] = '掛載模組';
$_LANG['ai_module_select'] = '請選擇模組';
$_LANG['ai_module_cue'] = '選擇應用掛載的內容創作模組（僅按鈕位置為詳情頁 / 列表頁需要）';
$_LANG['ai_module_required'] = '請選擇掛載模組';
$_LANG['ai_field'] = '產生欄位';
$_LANG['ai_field_select_module_first'] = '請先選擇模組';
$_LANG['ai_field_cue'] = '勾選 AI 要產生的欄位';
$_LANG['ai_field_required'] = '請至少勾選一個產生欄位';
$_LANG['ai_prompt_catalog_missing'] = '內建提示詞設定缺失或損壞，請檢查 config/ai.php';
$_LANG['ai_field_cue_translate'] = '勾選哪些欄位，對應多語言彈窗才會顯示翻譯按鈕；圖片欄位即使勾選也不會顯示';
$_LANG['ai_advanced'] = '進階設定';
$_LANG['ai_model_select'] = '請選擇模型';
$_LANG['ai_model_group_select'] = '請選擇';
$_LANG['ai_model_default'] = '預設模型';
$_LANG['ai_model_cue'] = '該應用呼叫的 AI 模型';
$_LANG['ai_default_prompt'] = '系統提示詞';
$_LANG['ai_default_prompt_cue'] = '可選。作為管理員個性化補充接在通用說明之後，與上文衝突時以這段為準（例如固定文風、必含要素等）';
$_LANG['ai_config'] = '應用設定';
$_LANG['ai_config_cue'] = 'JSON 格式的應用設定，如：{"batch_min":1,"batch_max":20}；Banner應用可配 {"canvas_size":"1920x400","content_align":"center"}（canvas_size 畫布寬x高，content_align 取值 left/center/right、標題文字水平對齊的預設值，彈窗可改選；文字一律垂直置中）';
$_LANG['ai_status'] = '狀態';
$_LANG['ai_status_0'] = '停用';
$_LANG['ai_status_1'] = '啟用';
$_LANG['ai_status_toggle_cue'] = '點擊啟用/停用該應用';
$_LANG['ai_created_at'] = '建立時間';

// AI 產生執行時
$_LANG['ai_generate'] = 'AI 產生';
$_LANG['ai_generate_batch'] = 'AI 批次產生';
$_LANG['ai_generate_count'] = '產生數量';
$_LANG['ai_generate_prompt'] = '產生要求';
$_LANG['ai_generate_prompt_cue'] = '描述要產生的內容主題、風格等要求';
$_LANG['ai_generate_app_not_found'] = 'AI 應用不存在或已停用';
$_LANG['ai_generate_failed'] = 'AI 產生失敗';
$_LANG['ai_generating'] = '正在產生，請稍候…';
$_LANG['ai_generating_btn'] = '產生中...';
$_LANG['ai_generating_translate'] = '正在產生翻譯結果...';
$_LANG['ai_generate_invalid_json'] = 'AI 返回內容解析失敗，請重試';
$_LANG['ai_generate_count_invalid'] = '產生數量超出允許範圍';
$_LANG['ai_generate_module_unsupported'] = '該模組暫不支援批次入庫';
$_LANG['ai_generate_success'] = '產生成功';
$_LANG['ai_generate_batch_result'] = '批次產生完成：成功 %s 條，失敗 %s 條';
$_LANG['ai_generate_empty'] = 'AI 未返回任何內容，請調整提示詞後重試';
$_LANG['ai_generate_prompt_field_cue'] = '可描述最佳化方向；留空則基於當前欄位內容與表單上下文產生';
$_LANG['ai_prompt_show_system'] = '顯示系統提示詞';
$_LANG['ai_prompt_hide_system'] = '隱藏系統提示詞';
$_LANG['ai_prompt_preview_failed'] = '提示詞取得失敗';
$_LANG['ai_translate_empty_source'] = '請先填寫原文';
$_LANG['ai_lang_clear'] = '清空';

// 輸出契約欄位約束文案（拼入 JSON Schema description，供 LLM 理解）
$_LANG['ai_schema_hint_title'] = '簡潔有吸引力的標題，純文字';
$_LANG['ai_schema_hint_keywords'] = 'SEO 關鍵字，3-5 個，用英文逗號分隔';
$_LANG['ai_schema_hint_summary'] = '概要描述，純文字，不含 HTML 標籤';
$_LANG['ai_schema_hint_content'] = '正文內容，使用 HTML 段落標籤組織，結構完整';
$_LANG['ai_schema_hint_integer'] = '整數';
$_LANG['ai_schema_hint_number'] = '數值';
$_LANG['ai_schema_hint_maxlength'] = '不超過 %d 個字元';
$_LANG['ai_import_item_invalid'] = '條目格式不正確';
$_LANG['ai_import_missing_field'] = '缺少必填欄位';

// AI 供應商
$_LANG['ai_provider_has_chats'] = '該供應商存在會話記錄，無法刪除';
$_LANG['ai_provider_has_logs'] = '該供應商存在使用日誌，無法刪除';
$_LANG['ai_provider_has_apps'] = '該供應商下的模型已被 AI 應用引用，無法刪除';

// AI 供應商欄位
$_LANG['ai_provider_name'] = '供應商名稱';
$_LANG['ai_provider_code'] = '供應商代碼';
$_LANG['ai_provider_code_cue'] = '唯一標識，如：openai、anthropic、deepseek';
$_LANG['ai_provider_base_url'] = 'API 基礎地址';
$_LANG['ai_provider_base_url_cue'] = '如：https://api.openai.com/v1';
$_LANG['ai_provider_advanced'] = '進階設定';
$_LANG['ai_provider_config'] = '擴充設定';
$_LANG['ai_provider_config_cue'] = 'JSON 格式的供應商擴充設定，如：{"supports_json_schema":true,"endpoints":{"text":"/chat/completions"}}（endpoints 可覆蓋端點路徑）';
$_LANG['ai_provider_created_at'] = '建立時間';
$_LANG['ai_provider_code_existed'] = '供應商代碼已存在，請更換其它代碼';
$_LANG['ai_provider_key_list'] = 'API 金鑰';
$_LANG['ai_provider_key_list_cue'] = '儲存供應商時一併建立/更新金鑰；金鑰留空表示不修改';
$_LANG['ai_provider_model_list_cue'] = '儲存供應商時一併管理模型；模型名稱與模型代碼均填寫才會儲存';
$_LANG['ai_provider_status_cue'] = '停用後該供應商及其模型將無法被 AI 應用、生成任務調用';
$_LANG['ai_toggle_failed'] = '操作失敗，請重試';
$_LANG['ai_provider_toggle_cue'] = '點擊啟用/停用該供應商';
$_LANG['ai_provider_enable_succes'] = '供應商已啟用';
$_LANG['ai_provider_disable_succes'] = '供應商已停用';
$_LANG['ai_provider_batch_enable'] = '啟用所選供應商';
$_LANG['ai_provider_batch_disable'] = '停用所選供應商';
$_LANG['ai_key_none'] = '未配置';
$_LANG['ai_provider_key_count_cue'] = '已配置的 API 金鑰數量';
$_LANG['ai_provider_key_missing_cue'] = '尚未配置 API 金鑰，點擊進入編輯頁新增';
$_LANG['ai_provider_setup_notice'] = '系統偵測到還沒有配置任何 API 金鑰：沒有金鑰，AI 應用與生成任務將無法調用模型。請點擊供應商名稱進入編輯頁，在「API 金鑰」區新增金鑰並儲存。';

// AI 模型
$_LANG['ai_model'] = 'AI 模型';
$_LANG['ai_model_create'] = '新增模型';
$_LANG['ai_model_edit'] = '編輯模型';
$_LANG['ai_model_add_succes'] = '新增模型成功';
$_LANG['ai_model_edit_succes'] = '編輯模型成功';
$_LANG['ai_model_select_empty'] = '沒有選擇任何模型';
$_LANG['ai_model_add_inline'] = '新增模型';
$_LANG['ai_model_has_usage'] = '該模型存在使用記錄，無法刪除';
$_LANG['ai_model_has_chats'] = '該模型存在會話記錄，無法刪除';
$_LANG['ai_model_has_records'] = '該模型存在訊息記錄，無法刪除';
$_LANG['ai_model_has_apps'] = '該模型被 AI 應用引用，無法刪除';

// AI 模型欄位
$_LANG['ai_model_name'] = '模型名稱';
$_LANG['ai_model_code'] = '模型代碼';
$_LANG['ai_model_context_length'] = '上下文長度';
$_LANG['ai_model_max_tokens'] = '最大輸出 token 數';
$_LANG['ai_model_code_existed'] = '模型代碼已存在，請更換其它代碼';

// AI 金鑰
$_LANG['ai_key'] = 'API 金鑰';
$_LANG['ai_key_list'] = '金鑰列表';
$_LANG['ai_key_create'] = '新增金鑰';
$_LANG['ai_key_edit'] = '編輯金鑰';
$_LANG['ai_key_delete'] = '刪除金鑰';
$_LANG['ai_key_add_succes'] = '新增金鑰成功';
$_LANG['ai_key_edit_succes'] = '編輯金鑰成功';
$_LANG['ai_key_select_empty'] = '沒有選擇任何金鑰';
$_LANG['ai_key_has_usage'] = '該金鑰存在使用記錄，無法刪除';

// AI 金鑰欄位
$_LANG['ai_key_provider'] = '所屬供應商';
$_LANG['ai_key_api_key'] = 'API 金鑰';
$_LANG['ai_key_api_key_cue'] = '金鑰以明文儲存，請確保資料庫與伺服器存取安全';
$_LANG['ai_key_alias'] = '金鑰別名';
$_LANG['ai_key_alias_cue'] = '便於識別的名稱';
$_LANG['ai_key_expires_at'] = '過期時間';
$_LANG['ai_key_expires_at_cue'] = '不填表示永久有效';
$_LANG['ai_key_last_used_at'] = '最後使用時間';
$_LANG['ai_key_failure_count'] = '失敗次數';
$_LANG['ai_key_config'] = '擴充設定';
$_LANG['ai_key_config_cue'] = 'JSON 格式的擴充設定，如百度 client_secret 會自動寫入';
$_LANG['ai_key_created_at'] = '建立時間';
$_LANG['ai_key_add_inline'] = '新增金鑰';
$_LANG['ai_key_advanced_options'] = '進階選項';
$_LANG['ai_key_save'] = '儲存';
$_LANG['ai_key_cancel'] = '取消';
$_LANG['ai_key_reset'] = '重置';
$_LANG['ai_key_reset_succes'] = '金鑰失敗計數已重置';
$_LANG['ai_key_reset_confirm'] = '確定重置該金鑰的失敗計數？';
$_LANG['ai_key_api_key_keep'] = '已配置-輸入新值可替換';
$_LANG['ai_key_api_key_required'] = '請至少新增一把有效的API金鑰';
$_LANG['ai_key_has_chats'] = '該金鑰存在對話記錄，無法刪除';
$_LANG['ai_key_id_fallback'] = '金鑰ID:%s';

// 使用日誌
$_LANG['ai_log'] = '使用日誌';
$_LANG['ai_log_list'] = '日誌列表';
$_LANG['ai_log_show'] = '日誌詳情';
$_LANG['ai_log_delete'] = '刪除日誌';
$_LANG['ai_log_select_empty'] = '沒有選擇任何日誌';

// 使用日誌欄位
$_LANG['ai_log_admin'] = '管理員';
$_LANG['ai_log_admin_id'] = '管理員 ID';
$_LANG['ai_log_app'] = '應用';
$_LANG['ai_log_provider'] = '供應商';
$_LANG['ai_log_model'] = '模型';
$_LANG['ai_log_key'] = '金鑰';
$_LANG['ai_log_request_id'] = '請求 ID';
$_LANG['ai_log_prompt_tokens'] = '輸入 Tokens';
$_LANG['ai_log_completion_tokens'] = '輸出 Tokens';
$_LANG['ai_log_total_tokens'] = '總 Tokens';
$_LANG['ai_log_duration'] = '耗時';
$_LANG['ai_log_duration_ms'] = '毫秒';
$_LANG['ai_log_status_code'] = '狀態碼';
$_LANG['ai_log_has_error'] = '狀態';
$_LANG['ai_log_has_error_0'] = '成功';
$_LANG['ai_log_has_error_1'] = '失敗';
$_LANG['ai_log_error_message'] = '錯誤訊息';
$_LANG['ai_log_endpoint'] = 'API 端點';
$_LANG['ai_log_metadata'] = '元資料';
$_LANG['ai_log_prompt_content'] = '最終提示詞';
$_LANG['ai_log_response_content'] = 'AI 返回內容';
$_LANG['ai_log_ip_address'] = 'IP 地址';
$_LANG['ai_log_created_at'] = '請求時間';
$_LANG['ai_log_basic_info'] = '基本資訊';
$_LANG['ai_log_token_info'] = 'Token 資訊';
$_LANG['ai_log_request_info'] = '請求資訊';

// AI 非同步任務
$_LANG['ai_task'] = 'AI任務';
$_LANG['ai_task_list'] = '任務列表';
$_LANG['ai_task_delete'] = '刪除任務';
$_LANG['ai_task_submit_failed'] = '任務提交失敗';

// AI 非同步任務欄位
$_LANG['ai_task_app'] = '應用';
$_LANG['ai_task_provider'] = '供應商';
$_LANG['ai_task_model'] = '模型';
$_LANG['ai_async_type'] = '任務類型';
$_LANG['ai_async_type_image'] = '圖像';
$_LANG['ai_async_type_video'] = '影片';
$_LANG['ai_async_type_audio'] = '音訊';
$_LANG['ai_async_type_all'] = '全部類型';
$_LANG['ai_task_status'] = '狀態';
$_LANG['ai_task_status_pending'] = '等待中';
$_LANG['ai_task_status_running'] = '生成中';
$_LANG['ai_task_status_succeeded'] = '已完成';
$_LANG['ai_task_status_failed'] = '失敗';
$_LANG['ai_task_status_timeout'] = '已逾時';
$_LANG['ai_task_status_all'] = '全部狀態';
$_LANG['ai_task_created_at'] = '提交時間';
$_LANG['ai_task_updated_at'] = '更新時間';
$_LANG['ai_task_result'] = '生成結果';
$_LANG['ai_task_result_expired'] = '結果已過期，請重新生成';
$_LANG['ai_task_polling'] = '正在生成，請稍候（長影片可能需要幾分鐘）…';
$_LANG['ai_task_poll_network'] = '網路不穩定，任務仍在後台執行，可稍後在「AI任務」列表查看結果';
$_LANG['ai_task_result_title'] = '生成結果';
$_LANG['ai_task_copy'] = '複製連結';
$_LANG['ai_task_copy_done'] = '連結已複製';
$_LANG['ai_task_view'] = '查看原檔案';
$_LANG['ai_task_close'] = '關閉';
$_LANG['ai_task_not_succeeded'] = '任務尚未完成，請稍後再試';
$_LANG['ai_image_generate'] = 'AI 生成圖';
$_LANG['ai_image_ratio_custom'] = '自定義';
$_LANG['ai_align_left'] = '左對齊';
$_LANG['ai_align_right'] = '右對齊';
$_LANG['ai_align_center'] = '置中對齊';
$_LANG['ai_layout_title'] = '構圖與佈局';
$_LANG['ai_size_title'] = '尺寸';

// Banner 彈窗（幻燈輔助生成）
$_LANG['ai_banner_material'] = '圖片素材';
$_LANG['ai_banner_material_cue'] = '可選：選產品主圖或上傳本機圖片，勾選後僅摳出素材主體';
$_LANG['ai_banner_material_mode_subject'] = '摳出主體';
$_LANG['ai_banner_material_product'] = '選擇產品主圖';
$_LANG['ai_banner_material_upload'] = '本地上傳';
$_LANG['ai_banner_material_search'] = '輸入產品名稱，自動搜尋';
$_LANG['ai_banner_material_empty'] = '沒有可用的產品主圖';
$_LANG['ai_banner_material_max'] = '最多選擇 4 張素材';
$_LANG['ai_banner_material_invalid'] = '僅支援 5MB 以內的圖片檔案';
$_LANG['ai_banner_title'] = '主標題';
$_LANG['ai_banner_title_cue'] = '畫進 banner 的大字標題，如公司口號';
$_LANG['ai_banner_subtitle'] = '副標題';
$_LANG['ai_banner_subtitle_cue'] = '畫進 banner 的小字說明，如活動資訊';
$_LANG['ai_banner_style'] = '風格';
$_LANG['ai_banner_style_business'] = '商務簡約';
$_LANG['ai_banner_style_tech'] = '科技藍調';
$_LANG['ai_banner_style_vibrant'] = '活力漸層';
$_LANG['ai_banner_style_promo'] = '電商促銷';
$_LANG['ai_banner_style_fresh'] = '自然清新';
$_LANG['ai_banner_style_industry'] = '工業質感';
$_LANG['ai_banner_style_none'] = '不指定';
$_LANG['ai_banner_prompt_cue'] = '補充畫面細節，如：以車間設備為主體、藍色科技光效、右側留白放文字；留空則按標題與風格自動生成';
$_LANG['ai_image_use'] = '使用此圖';
$_LANG['ai_image_crop'] = '裁剪';
$_LANG['ai_image_regenerate'] = '重新生成';
$_LANG['ai_image_crop_loading'] = '正在開啟裁剪…';
$_LANG['ai_image_loading'] = '獲取中...';
$_LANG['ai_image_fetch_failed'] = '獲取圖片失敗';
$_LANG['ai_image_field_missing'] = '未找到目標圖片欄位';

// 核心層錯誤 / JSON 指令（admin 與前台 ai.lang.php 同步）
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
$_LANG['ai_task_placement_mismatch'] = '應用用途與任務類型不匹配';
$_LANG['ai_model_task_mismatch'] = '所選模型不支援目前應用用途，請更換模型';
$_LANG['ai_generate_input_too_large'] = '提交的表單內容或圖片素材過多';
$_LANG['ai_generate_duplicate_batch'] = '相同的批次生成要求正在處理或剛完成，請稍後再試';
$_LANG['ai_nested_input_invalid'] = '金鑰或模型參數格式不正確';
