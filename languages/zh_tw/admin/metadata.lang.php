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

$_LANG['metadata'] = '擴展字段';
$_LANG['metadata_slug'] = '字段標識';
$_LANG['metadata_label'] = '顯示名';
$_LANG['metadata_type'] = '字段類型';
$_LANG['metadata_required'] = '必填';
$_LANG['metadata_limit'] = '數量上限';
$_LANG['metadata_sort'] = '排序';
$_LANG['metadata_status'] = '狀態';
$_LANG['metadata_status_on'] = '啟用';
$_LANG['metadata_status_off'] = '禁用';
$_LANG['metadata_edit'] = '編輯擴展字段';
$_LANG['metadata_create'] = '新增擴展字段';
$_LANG['metadata_delete_confirm'] = '刪除後已填寫的數據與附件仍會保留，重建同標識字段即可恢復展示。確定刪除？';
$_LANG['metadata_options'] = '選項';
$_LANG['metadata_options_tip'] = 'select / radio / checkbox 使用，每行一個';
$_LANG['metadata_extensions'] = '允許擴展名';
$_LANG['metadata_extensions_tip'] = '僅 file 類型生效；可填一個或多個擴展名，多個用英文逗號分隔（例：jpg,png,pdf），不要帶點；只能填寫平台基線內的擴展名，留空表示使用全部基線';
$_LANG['metadata_extensions_baseline'] = '平台基線';
$_LANG['metadata_max_kb'] = '大小上限(KB)';
$_LANG['metadata_max_kb_tip'] = '僅 file 類型生效；0 表示平台默認，且不能超過平台值';
$_LANG['metadata_limit_tip'] = 'gallery / checkbox 使用；0 表示 gallery 默認 6 個槽位、checkbox 不限制';
$_LANG['metadata_tip'] = '提示文案';
$_LANG['metadata_slug_tip'] = '小寫字母開頭，僅含小寫字母、數字、下劃線，最長 30 位；創建後不可修改';
$_LANG['metadata_cue_not_ready'] = '擴展字段定義表尚未就緒，請先升級系統。';
$_LANG['metadata_default_language'] = '默認語言';
$_LANG['metadata_list_title'] = '擴展字段';
$_LANG['metadata_list_empty'] = '暫無擴展字段';
$_LANG['metadata_field_unit'] = '個字段';
$_LANG['metadata_module'] = '所屬模塊';
$_LANG['metadata_module_locked'] = '所屬模塊由列表分組入口決定，不可修改';
$_LANG['metadata_create_need_module'] = '請從對應模塊分組點擊「新增」創建擴展字段';
$_LANG['metadata_section_basic'] = '基礎信息';
$_LANG['metadata_section_control'] = '控件屬性';
$_LANG['metadata_section_display'] = '顯示與狀態';
$_LANG['metadata_type_locked'] = '字段類型創建後不可修改，如需變更請刪除後重建';
$_LANG['metadata_type_switch_tip'] = '切換類型會顯示該類型可用的配置項';
$_LANG['metadata_sort_tip'] = '「排序」只在所屬模塊內生效，數值越小越靠前。';
$_LANG['metadata_create_success'] = '擴展字段已創建';
$_LANG['metadata_update_success'] = '擴展字段已保存';
$_LANG['metadata_delete_success'] = '擴展字段已刪除';
$_LANG['metadata_inherit_default'] = '繼承默認';
$_LANG['metadata_clear'] = '清除';
$_LANG['metadata_download'] = '下載';

$_LANG['metadata_type_input'] = '單行文本';
$_LANG['metadata_type_textarea'] = '多行文本';
$_LANG['metadata_type_number'] = '數字';
$_LANG['metadata_type_date'] = '日期';
$_LANG['metadata_type_select'] = '下拉選擇';
$_LANG['metadata_type_radio'] = '單選';
$_LANG['metadata_type_checkbox'] = '多選';
$_LANG['metadata_type_switch'] = '開關';
$_LANG['metadata_type_image'] = '單圖';
$_LANG['metadata_type_gallery'] = '多圖';
$_LANG['metadata_type_file'] = '文件';
$_LANG['metadata_type_editor'] = '富文本';

$_LANG['metadata_error_module'] = '所屬模塊不合法';
$_LANG['metadata_error_slug'] = '字段標識必須為小寫字母開頭，僅含小寫字母、數字、下劃線，最長 30 位';
$_LANG['metadata_error_label'] = '顯示名不能為空且不能超過 150 個字符';
$_LANG['metadata_error_type'] = '字段類型不合法';
$_LANG['metadata_error_slug_locked'] = '字段標識創建後不可修改';
$_LANG['metadata_error_type_locked'] = '字段類型創建後不可修改';
$_LANG['metadata_error_options'] = '選擇類字段必須填寫至少一個選項';
$_LANG['metadata_error_max_kb'] = '文件大小上限不能超過平台默認值';
$_LANG['metadata_error_limit'] = '數量上限必須在 0 到 20 之間';
$_LANG['metadata_error_not_ready'] = '擴展字段定義表尚未就緒，請先升級系統';
$_LANG['metadata_error_slug_exists'] = '該模塊下字段標識已存在';
$_LANG['metadata_error_limit_reached'] = '單個模塊的擴展字段數量已達上限';
$_LANG['metadata_error_not_found'] = '擴展字段不存在';
$_LANG['metadata_error_extension_format'] = '擴展名格式不合法';
$_LANG['metadata_error_extension_dangerous'] = '包含被禁止的危險擴展名';
$_LANG['metadata_error_extension_outside'] = '擴展名超出平台允許範圍';
$_LANG['metadata_error_extension_empty'] = '至少填寫一個允許的擴展名';
$_LANG['metadata_error_attachment'] = '附件編號不合法';
$_LANG['metadata_error_attachment_tamper'] = '附件編號與當前記錄不一致，請刷新後重試';
$_LANG['metadata_error_upload'] = '文件上傳失敗';
$_LANG['metadata_error_encode'] = '擴展字段數據編碼失敗';
$_LANG['metadata_error_save_failed'] = '擴展字段保存失敗，請檢查字段內容編碼或數據庫日誌';
$_LANG['metadata_error_label_too_long'] = '顯示名不能超過 150 個字符';
