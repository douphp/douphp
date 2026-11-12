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

// 導航
$_LANG['nav_aigc'] = 'AI應用';

// 會員 AI 應用
$_LANG['aigc'] = 'AI應用';
$_LANG['aigc_list'] = '應用列表';
$_LANG['aigc_create'] = '新增應用';
$_LANG['aigc_edit'] = '編輯應用';
$_LANG['aigc_add_succes'] = '新增應用成功';
$_LANG['aigc_edit_succes'] = '編輯應用成功';
$_LANG['aigc_select_empty'] = '沒有選擇任何應用';
$_LANG['aigc_delete_has_data'] = '應用「d%」已有會話或任務記錄，不能刪除；請先清理相關資料';
$_LANG['aigc_user_not_found'] = '未找到該會員，已顯示空結果';

// 應用欄位
$_LANG['aigc_name'] = '應用名稱';
$_LANG['aigc_slug'] = '識別碼';
$_LANG['aigc_slug_cue'] = '應用唯一識別碼，僅限字母、數字、底線和中線';
$_LANG['aigc_type'] = '應用類型';
$_LANG['aigc_type_chat'] = '文字對話';
$_LANG['aigc_type_image'] = '圖片生成';
$_LANG['aigc_type_video'] = '影片生成';
$_LANG['aigc_type_audio'] = '音訊生成';
$_LANG['aigc_type_ppt'] = 'PPT生成';
$_LANG['aigc_type_excel'] = 'Excel生成';
$_LANG['aigc_type_cue'] = '應用類型決定會員端可用的生成能力；文字對話支援多輪上下文與推薦問題。';

// 應用表單欄位
$_LANG['aigc_field'] = '表單欄位';
$_LANG['aigc_field_manage'] = '表單欄位管理';
$_LANG['aigc_field_list'] = '欄位列表';
$_LANG['aigc_field_manage_link'] = '管理欄位';
$_LANG['aigc_field_create'] = '新增欄位';
$_LANG['aigc_field_edit'] = '編輯欄位';
$_LANG['aigc_field_add_succes'] = '新增欄位成功';
$_LANG['aigc_field_edit_succes'] = '編輯欄位成功';
$_LANG['aigc_field_app_missing'] = '應用不存在';
$_LANG['aigc_field_slug_exists'] = '同一應用內欄位識別碼不能重複';
$_LANG['aigc_field_empty'] = '暫無欄位，會員端將使用預設提示詞輸入框';
$_LANG['aigc_field_cue'] = '為應用設定會員端填寫表單；欄位值會作為生成參數傳給模型';
$_LANG['aigc_field_form_cue'] = '設定應用表單欄位';
$_LANG['aigc_field_name'] = '欄位名稱';
$_LANG['aigc_field_slug'] = '欄位識別碼';
$_LANG['aigc_field_slug_cue'] = '小寫字母開頭，僅限字母、數字、底線；尺寸/數量/時長/解析度/音色/語速/頁數/工作表數/風格等識別碼會直接作為生成參數';
$_LANG['aigc_field_type'] = '欄位類型';
$_LANG['aigc_field_type_input'] = '單行文字';
$_LANG['aigc_field_type_textarea'] = '多行文字';
$_LANG['aigc_field_type_number'] = '數字';
$_LANG['aigc_field_type_select'] = '下拉選單';
$_LANG['aigc_field_type_radio'] = '單選';
$_LANG['aigc_field_type_checkbox'] = '多選';
$_LANG['aigc_field_option'] = '選項';
$_LANG['aigc_field_option_cue'] = '每行一個選項，格式：值|顯示名稱；只寫顯示名稱時值同顯示名稱。僅下拉/單選/多選使用';
$_LANG['aigc_field_placeholder'] = '佔位提示';
$_LANG['aigc_field_default'] = '預設值';
$_LANG['aigc_field_tip'] = '欄位說明';
$_LANG['aigc_field_must_fill'] = '填寫要求';
$_LANG['aigc_field_must_fill_0'] = '選填';
$_LANG['aigc_field_must_fill_1'] = '必填';
$_LANG['aigc_field_width'] = '寬度';
$_LANG['aigc_field_width_cue'] = '1-12 柵格寬度，12 為整行';
$_LANG['aigc_field_sort'] = '排序';
$_LANG['aigc_field_status'] = '狀態';
$_LANG['aigc_description'] = '應用描述';
$_LANG['aigc_icon'] = '圖示';
$_LANG['aigc_cover_image'] = '封面圖';
$_LANG['aigc_model'] = '可用模型';
$_LANG['aigc_model_cue'] = '選擇該應用可使用的AI模型（必選，至少一個）';
$_LANG['aigc_model_available'] = '可選模型';
$_LANG['aigc_model_selected'] = '已選模型';
$_LANG['aigc_default_prompt'] = '系統提示詞';
$_LANG['aigc_default_prompt_cue'] = '作為 system 角色注入對話上下文，用於設定 AI 的角色與行為';
$_LANG['aigc_feedback_up'] = '有用（讚）';
$_LANG['aigc_feedback_down'] = '無用（踩）';
$_LANG['aigc_suggested_questions'] = '推薦問題';
$_LANG['aigc_suggested_questions_cue'] = '每行一條，最多 6 條；僅文字對話類型使用，展示在會話歡迎區。';
$_LANG['aigc_config'] = '擴充設定';
$_LANG['aigc_config_cue'] = 'JSON 格式的擴充設定，如溫度、最大輸出等參數';
$_LANG['aigc_fee_mode'] = '收費模式';
$_LANG['aigc_fee_mode_cue'] = '免費應用不消耗會員訂閱配額，但受每日限額約束；付費應用按訂閱配額扣減';
$_LANG['aigc_is_free'] = '收費模式';
$_LANG['aigc_is_free_0'] = '付費';
$_LANG['aigc_is_free_1'] = '免費';
$_LANG['aigc_free_limit'] = '免費每日限額';
$_LANG['aigc_free_limit_type_requests'] = '按次數';
$_LANG['aigc_free_limit_type_tokens'] = '按tokens';
$_LANG['aigc_free_limit_cue'] = '每個會員每天可用量，0 表示不限制，按自然日重置';
$_LANG['aigc_free_zero_warning'] = '免費應用每日限額為 0（不限），所有會員可無限使用，請確認成本風險。';
$_LANG['aigc_is_public'] = '是否公開';
$_LANG['aigc_is_public_1'] = '公開';
$_LANG['aigc_is_public_0'] = '隱藏';
$_LANG['aigc_sort'] = '排序';
$_LANG['aigc_status'] = '狀態';
$_LANG['aigc_status_1'] = '啟用';
$_LANG['aigc_status_0'] = '停用';

// 會話
$_LANG['aigc_session'] = '會話管理';
$_LANG['aigc_session_list'] = '會話列表';
$_LANG['aigc_session_show'] = '會話詳情';
$_LANG['aigc_session_select_empty'] = '沒有選擇任何會話';
$_LANG['aigc_session_app'] = '應用';
$_LANG['aigc_session_title'] = '會話標題';
$_LANG['aigc_session_user'] = '會員';
$_LANG['aigc_session_username'] = '會員名稱';
$_LANG['aigc_session_keyword'] = '標題關鍵字';
$_LANG['aigc_session_status'] = '狀態';
$_LANG['aigc_session_status_active'] = '進行中';
$_LANG['aigc_session_status_closed'] = '已關閉';
$_LANG['aigc_session_message_count'] = '訊息數';
$_LANG['aigc_session_last_active'] = '最近活動';
$_LANG['aigc_session_close'] = '關閉會話';
$_LANG['aigc_session_close_succes'] = '會話已關閉';
$_LANG['aigc_session_load_earlier'] = '載入更早訊息';
$_LANG['aigc_session_back_latest'] = '返回最新';

// 訊息角色
$_LANG['aigc_message_role_user'] = '會員';
$_LANG['aigc_message_role_ai'] = 'AI';
$_LANG['aigc_message_role_system'] = '系統';

// 套餐
$_LANG['aigc_package'] = 'AI套餐';
$_LANG['aigc_package_create'] = '新增套餐';
$_LANG['aigc_package_edit'] = '編輯套餐';
$_LANG['aigc_package_add_succes'] = '新增套餐成功';
$_LANG['aigc_package_edit_succes'] = '編輯套餐成功';
$_LANG['aigc_package_select_empty'] = '沒有選擇任何套餐';
$_LANG['aigc_package_has_subscription'] = '該套餐已有訂閱記錄，不能刪除';
$_LANG['aigc_package_name'] = '套餐名稱';
$_LANG['aigc_package_day'] = '有效期';
$_LANG['aigc_package_day_unit'] = '天';
$_LANG['aigc_package_day_cue'] = '購買後的有效天數';
$_LANG['aigc_package_tokens_limit'] = 'Tokens額度';
$_LANG['aigc_package_tokens_limit_cue'] = '套餐包含的tokens總量，0表示不限制';
$_LANG['aigc_package_requests_limit'] = '請求次數';
$_LANG['aigc_package_requests_limit_cue'] = '套餐包含的請求次數，0表示不限制';
$_LANG['aigc_package_price'] = '價格';
$_LANG['aigc_package_price_cue'] = '套餐售價，單位元';
$_LANG['aigc_package_content'] = '套餐說明';
$_LANG['aigc_package_content_cue'] = '展示給會員的套餐說明，支援HTML';
$_LANG['aigc_package_unlimited'] = '不限制';
$_LANG['aigc_package_status_1'] = '上架';
$_LANG['aigc_package_status_0'] = '下架';

// 訂閱
$_LANG['aigc_subscription'] = '訂閱記錄';
$_LANG['aigc_subscription_list'] = '訂閱列表';
$_LANG['aigc_subscription_show'] = '訂閱詳情';
$_LANG['aigc_subscription_select_empty'] = '沒有選擇任何訂閱記錄';
$_LANG['aigc_subscription_user'] = '會員';
$_LANG['aigc_subscription_package'] = '套餐';
$_LANG['aigc_subscription_order_sn'] = '訂單號';
$_LANG['aigc_subscription_price'] = '價格';
$_LANG['aigc_subscription_usage'] = '用量';
$_LANG['aigc_subscription_requests'] = '請求';
$_LANG['aigc_subscription_order_status'] = '訂單狀態';
$_LANG['aigc_subscription_order_status_pending'] = '待付款';
$_LANG['aigc_subscription_order_status_awaiting_confirmation'] = '待確認';
$_LANG['aigc_subscription_order_status_paid'] = '已付款';
$_LANG['aigc_subscription_order_status_completed'] = '已完成';
$_LANG['aigc_subscription_order_status_cancelled'] = '已取消';
$_LANG['aigc_subscription_sale_price_type_discount'] = '折扣';
$_LANG['aigc_subscription_sale_price_type_fixed'] = '固定價';
$_LANG['aigc_subscription_created_at'] = '下單時間';
$_LANG['aigc_subscription_start_at'] = '生效時間';
$_LANG['aigc_subscription_end_at'] = '到期時間';
$_LANG['aigc_subscription_expired'] = '已過期';
$_LANG['aigc_subscription_valid'] = '有效';
$_LANG['aigc_subscription_basic_info'] = '基本資訊';
$_LANG['aigc_subscription_quota_info'] = '額度資訊';
$_LANG['aigc_subscription_time_info'] = '時間資訊';
$_LANG['aigc_subscription_tokens_limit'] = 'Tokens額度';
$_LANG['aigc_subscription_tokens_used'] = '已用Tokens';
$_LANG['aigc_subscription_tokens_remaining'] = '剩餘Tokens';
$_LANG['aigc_subscription_tokens_percent'] = 'Tokens使用率';
$_LANG['aigc_subscription_requests_limit'] = '請求次數';
$_LANG['aigc_subscription_requests_used'] = '已用次數';
$_LANG['aigc_subscription_requests_remaining'] = '剩餘次數';
$_LANG['aigc_subscription_requests_percent'] = '次數使用率';

// 配額
$_LANG['aigc_quota'] = '會員配額';
$_LANG['aigc_quota_create'] = '新增配額';
$_LANG['aigc_quota_edit'] = '編輯配額';
$_LANG['aigc_quota_add_succes'] = '新增配額成功';
$_LANG['aigc_quota_edit_succes'] = '編輯配額成功';
$_LANG['aigc_quota_select_empty'] = '沒有選擇任何配額';
$_LANG['aigc_quota_exists'] = '該會員已存在同類型配額';
$_LANG['aigc_quota_user_not_found'] = '會員不存在';
$_LANG['aigc_quota_user'] = '會員';
$_LANG['aigc_quota_username'] = '會員名稱';
$_LANG['aigc_quota_username_cue'] = '輸入會員使用者名稱或信箱';
$_LANG['aigc_quota_type'] = '配額類型';
$_LANG['aigc_quota_type_tokens'] = 'Tokens';
$_LANG['aigc_quota_type_requests'] = '請求次數';
$_LANG['aigc_quota_type_amount'] = '金額';
$_LANG['aigc_quota_total_limit'] = '總額度';
$_LANG['aigc_quota_total_limit_cue'] = '0表示不限制';
$_LANG['aigc_quota_used_amount'] = '已用量';
$_LANG['aigc_quota_usage_percent'] = '使用率';
$_LANG['aigc_quota_unlimited'] = '不限制';
$_LANG['aigc_quota_reset'] = '重置已用量';
$_LANG['aigc_quota_reset_succes'] = '重置已用量成功';
$_LANG['aigc_quota_reset_cycle'] = '重置週期';
$_LANG['aigc_quota_reset_cycle_never'] = '不重置';
$_LANG['aigc_quota_reset_cycle_daily'] = '每日';
$_LANG['aigc_quota_reset_cycle_weekly'] = '每週';
$_LANG['aigc_quota_reset_cycle_monthly'] = '每月';
$_LANG['aigc_quota_reset_cycle_yearly'] = '每年';
$_LANG['aigc_quota_expires_at'] = '過期時間';
$_LANG['aigc_quota_expires_at_cue'] = '留空表示永久有效';

// 用量日誌
$_LANG['aigc_usage_log'] = '用量日誌';
$_LANG['aigc_usage_log_list'] = '日誌列表';
$_LANG['aigc_usage_log_show'] = '日誌詳情';
$_LANG['aigc_usage_log_select_empty'] = '沒有選擇任何日誌';
$_LANG['aigc_usage_log_user'] = '會員';
$_LANG['aigc_usage_log_app'] = '應用';
$_LANG['aigc_usage_log_session'] = '會話ID';
$_LANG['aigc_usage_log_provider'] = '供應商';
$_LANG['aigc_usage_log_model'] = '模型';
$_LANG['aigc_usage_log_request_id'] = '請求ID';
$_LANG['aigc_usage_log_tokens'] = 'Tokens';
$_LANG['aigc_usage_log_prompt_tokens'] = '輸入Tokens';
$_LANG['aigc_usage_log_completion_tokens'] = '輸出Tokens';
$_LANG['aigc_usage_log_total_tokens'] = '總Tokens';
$_LANG['aigc_usage_log_duration'] = '耗時';
$_LANG['aigc_usage_log_status_code'] = '狀態碼';
$_LANG['aigc_usage_log_has_error'] = '狀態';
$_LANG['aigc_usage_log_has_error_0'] = '成功';
$_LANG['aigc_usage_log_has_error_1'] = '失敗';
$_LANG['aigc_usage_log_error_message'] = '錯誤訊息';
$_LANG['aigc_usage_log_endpoint'] = 'API端點';
$_LANG['aigc_usage_log_ip'] = 'IP位址';
$_LANG['aigc_usage_log_metadata'] = '中繼資料';
$_LANG['aigc_usage_log_created_at'] = '請求時間';
$_LANG['aigc_usage_log_basic_info'] = '基本資訊';
$_LANG['aigc_usage_log_token_info'] = 'Token資訊';

// 非同步任務
$_LANG['aigc_task'] = '生成任務';
$_LANG['aigc_task_show'] = '任務詳情';
$_LANG['aigc_task_not_terminal'] = '任務尚未結束，不能刪除';
$_LANG['aigc_task_basic_info'] = '基本資訊';
$_LANG['aigc_task_user'] = '會員';
$_LANG['aigc_task_app'] = '應用';
$_LANG['aigc_task_provider'] = '供應商';
$_LANG['aigc_task_model'] = '模型';
$_LANG['aigc_task_type'] = '任務類型';
$_LANG['aigc_task_status'] = '任務狀態';
$_LANG['aigc_task_result'] = '結果';
$_LANG['aigc_task_error'] = '錯誤訊息';
$_LANG['aigc_task_request_payload'] = '請求參數';
$_LANG['aigc_task_created_at'] = '提交時間';
$_LANG['aigc_task_updated_at'] = '更新時間';
$_LANG['aigc_task_type_all'] = '全部類型';
$_LANG['aigc_task_type_image'] = '圖片生成';
$_LANG['aigc_task_type_video'] = '影片生成';
$_LANG['aigc_task_type_audio'] = '音訊生成';
$_LANG['aigc_task_type_ppt'] = 'PPT生成';
$_LANG['aigc_task_type_excel'] = 'Excel生成';
$_LANG['aigc_task_status_all'] = '全部狀態';
$_LANG['aigc_task_status_pending'] = '待處理';
$_LANG['aigc_task_status_running'] = '執行中';
$_LANG['aigc_task_status_succeeded'] = '已完成';
$_LANG['aigc_task_status_failed'] = '失敗';
$_LANG['aigc_task_status_timeout'] = '逾時';
