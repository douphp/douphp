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

// 自訂表單 --------
$_LANG['form'] = '自訂表單';
$_LANG['form_list'] = '表單管理';
$_LANG['form_create'] = '新增表單';
$_LANG['form_edit'] = '編輯表單';
$_LANG['form_delete'] = '刪除表單';
$_LANG['form_url'] = '表單連結';

// 表單屬性
$_LANG['form_name'] = '表單名稱';
$_LANG['form_slug'] = '英文標記';
$_LANG['form_content'] = '表單介紹';
$_LANG['form_success_message'] = '提交成功提示';
$_LANG['form_success_url'] = '提交後跳轉連結';
$_LANG['form_captcha'] = '啟用驗證碼';
$_LANG['form_require_login'] = '僅登入會員可提交';
$_LANG['form_spam_limit'] = '同 IP 每小時上限';
$_LANG['form_notify_email'] = '新提交通知信箱';
$_LANG['form_status'] = '表單狀態';
$_LANG['form_status_0'] = '關閉';
$_LANG['form_status_1'] = '開啟';
$_LANG['form_sort'] = '排序';
$_LANG['form_created_at'] = '新增時間';
$_LANG['form_field_count'] = '欄位數';
$_LANG['form_data_count'] = '提交數';
$_LANG['form_list_empty'] = '還沒有表單，點擊右上角按鈕新增';
$_LANG['form_nav_empty'] = '還沒有表單';
$_LANG['form_create_success'] = '表單已建立，請繼續新增欄位';
$_LANG['form_update_success'] = '表單已更新';

// 嵌入代碼（表單列表 → 彈窗展示可複製的 HTML 範例）
$_LANG['form_embed'] = '嵌入代碼';
$_LANG['form_embed_cue'] = '把上面的 HTML 貼到任意前台 .dwt 模板即可使用該表單；$form.英文標記 由框架自動注入，控件可依需求改寫。';
$_LANG['form_embed_copy'] = '複製代碼';
$_LANG['form_embed_js'] = '代碼末尾已附帶前台腳本呼叫：預設主題的 js 目錄自帶這些檔案；若貼到其他模板，請把 theme/default/js 下的 alpine.min.js、dou.form.js、form.js 複製到當前模板的 js 目錄（表單含上傳欄位時再連帶 file.js）。';
$_LANG['form_embed_no_field'] = '該表單還沒有欄位，請先在欄位列表新增';

$_LANG['form_slug_cue'] = '用於模板呼叫，如 {$form.quote.contact.name}，小寫字母開頭，可含數字與底線';
$_LANG['form_slug_reserved'] = 'd% 是系統保留的模板鍵名，請更換';
$_LANG['form_slug_exists'] = '英文標記 d% 已被其他表單佔用';
$_LANG['form_success_message_cue'] = '留空則使用系統預設提示';
$_LANG['form_success_url_cue'] = '留空則提交後進入提交成功提示頁';
$_LANG['form_captcha_cue'] = '需同時開啟站點驗證碼總開關才會生效';
$_LANG['form_require_login_cue'] = '開啟後遊客看不到提交按鈕，上傳欄位始終要求登入';
$_LANG['form_spam_limit_cue'] = '同一 IP 每小時允許的提交次數，0 表示不限制';
$_LANG['form_notify_email_cue'] = '預留欄位，暫不發送郵件';

$_LANG['form_section_basic'] = '基本資訊';
$_LANG['form_section_submit'] = '提交行為';
$_LANG['form_section_fields'] = '表單欄位';

// 表單欄位 -------
$_LANG['form_field'] = '表單欄位';
$_LANG['form_field_list'] = '欄位列表';
$_LANG['form_field_create'] = '新增欄位';
$_LANG['form_field_edit'] = '編輯欄位';
$_LANG['form_field_delete'] = '刪除欄位';
$_LANG['form_field_empty'] = '還沒有欄位，點擊右上角新增';
$_LANG['form_field_manage'] = '管理欄位';
$_LANG['form_field_list_cue'] = '目前表單：d%，欄位異動會立即反映到前台表單';

$_LANG['form_field_name'] = '欄位名稱';
$_LANG['form_field_slug'] = '欄位鍵名';
$_LANG['form_field_type'] = '類型';
$_LANG['form_field_unit'] = '單位';
$_LANG['form_field_option'] = '可選項';
$_LANG['form_field_placeholder'] = '輸入提示';
$_LANG['form_field_tip'] = '欄位說明';
$_LANG['form_field_default_value'] = '預設值';
$_LANG['form_field_validate'] = '校驗規則';
$_LANG['form_field_width'] = '柵格寬度';
$_LANG['form_field_must_fill'] = '填寫要求';
$_LANG['form_field_must_fill_0'] = '可選';
$_LANG['form_field_must_fill_1'] = '必填';
$_LANG['form_field_limit'] = '最多上傳數';
$_LANG['form_field_limit_checkbox'] = '最多可選數';
$_LANG['form_field_extensions'] = '允許副檔名';
$_LANG['form_field_max_size'] = '單檔上限';
$_LANG['form_field_sort'] = '排序';
$_LANG['form_field_custom'] = '模板自訂欄位';
$_LANG['form_field_create_success'] = '欄位已新增';
$_LANG['form_field_update_success'] = '欄位已更新';
$_LANG['form_field_delete_success'] = '欄位已刪除';
$_LANG['form_agree_checked'] = '已同意';
$_LANG['form_agree_unchecked'] = '未同意';

$_LANG['form_field_slug_cue'] = '提交時的 name 與模板呼叫的鍵名，小寫字母開頭，可含數字與底線';
$_LANG['form_field_slug_exists'] = '欄位鍵名 d% 在本表單內已存在';
$_LANG['form_field_reserved'] = 'd% 是表單保留鍵名（如 name / content），請更換';
$_LANG['form_field_option_cue'] = '每行一個純值，下拉 / 單選 / 多選使用';
$_LANG['form_field_placeholder_cue'] = '輸入框灰色提示文字；下拉選取作為「請選擇」的佔位文案，勾選協議作為勾選框旁的協議文字，日期控制項不支援提示文字、僅在懸停時以標題顯示';
$_LANG['form_field_validate_cue'] = '多條用逗號分隔；切換欄位類型會自動帶入該類型的預設規則，可直接修改或刪除';
$_LANG['form_validate_email'] = '電子郵件格式';
$_LANG['form_validate_url'] = '網址格式';
$_LANG['form_validate_phone'] = '11 位手機號碼';
$_LANG['form_validate_numeric'] = '數字';
$_LANG['form_validate_date'] = '日期格式';
$_LANG['form_validate_min'] = '至少 2 字';
$_LANG['form_validate_max'] = '最多 200 字';
$_LANG['form_validate_min_value'] = '不小於 0';
$_LANG['form_validate_max_value'] = '不大於 1000';
$_LANG['form_validate_integer'] = '整數';
$_LANG['form_validate_idcard'] = '身分證號';
$_LANG['form_validate_postcode'] = '郵遞區號';
$_LANG['form_validate_qq'] = 'QQ 號';
$_LANG['form_validate_regex'] = '正規式';
$_LANG['form_validate_same'] = '與某欄位相同';
$_LANG['form_field_width_cue'] = '桌面端佔幾欄（共 12 欄）：6 為兩欄並排、4 為三欄並排、12 為獨佔一行；手機上始終獨佔一行';
$_LANG['form_field_limit_cue'] = '多圖 / 附件最多上傳數量，單圖固定 1 張';
$_LANG['form_field_limit_checkbox_cue'] = '多選最多能勾選幾項，0 表示不限';
$_LANG['form_field_default_value_cue'] = '選項類需與選項文字完全一致；多選預設值用逗號分隔多個值';
$_LANG['form_field_extensions_cue'] = '逗號分隔，留空則使用系統上傳策略';
$_LANG['form_field_extensions_baseline'] = '系統預設允許';
$_LANG['form_field_max_size_cue'] = '單位 KB，0 表示使用系統上傳策略';
$_LANG['form_field_slug_copy_cue'] = '點擊複製欄位鍵名';
$_LANG['form_field_section_attr'] = '欄位屬性';
$_LANG['form_field_section_upload'] = '上傳限制';

// 使用者提交的資料 -------
$_LANG['form_data'] = '表單資料';
$_LANG['form_data_list'] = '資料列表';
$_LANG['form_data_delete'] = '刪除表單資料';
$_LANG['form_data_view'] = '檢視提交';
$_LANG['form_data_empty'] = '暫無提交資料';

$_LANG['form_data_content'] = '提交內容';
$_LANG['form_data_form'] = '所屬表單';
$_LANG['form_data_ip'] = 'IP 位址';
$_LANG['form_data_source_url'] = '提交頁面';
$_LANG['form_data_user'] = '提交會員';
$_LANG['form_data_user_guest'] = '遊客';
$_LANG['form_data_if_read'] = '已讀';
$_LANG['form_data_if_read_0'] = '未讀';
$_LANG['form_data_if_read_1'] = '已讀';
$_LANG['form_data_status'] = '狀態';
$_LANG['form_data_status_0'] = '未處理';
$_LANG['form_data_status_1'] = '已處理';
$_LANG['form_data_created_at'] = '提交時間';
$_LANG['form_data_mark_all'] = '全部已處理';
$_LANG['form_data_mark_all_title'] = '全部標記為已處理';
$_LANG['form_data_mark_all_confirm'] = '確定把 d% 的全部提交標記為已讀且已處理？';
$_LANG['form_data_mark_all_success'] = '已將 d% 筆提交標記為已讀且已處理';

// 欄位類型（與 FormFieldSchema 一致）
$_LANG['form_type_input'] = '單行文字';
$_LANG['form_type_textarea'] = '多行文字';
$_LANG['form_type_select'] = '下拉選擇';
$_LANG['form_type_radio'] = '單選按鈕';
$_LANG['form_type_checkbox'] = '多選複選';
$_LANG['form_type_agree'] = '勾選協議';
$_LANG['form_type_switch'] = '開關';
$_LANG['form_type_number'] = '數字';
$_LANG['form_type_email'] = '電子郵件';
$_LANG['form_type_tel'] = '電話/市話';
$_LANG['form_type_mobile'] = '手機號碼';
$_LANG['form_type_url'] = '網址';
$_LANG['form_type_date'] = '日期';
$_LANG['form_type_area'] = '省市區';
$_LANG['form_type_module_missing'] = '（模組未安裝）';
$_LANG['form_type_image'] = '單圖上傳';
$_LANG['form_type_gallery'] = '多圖上傳';
$_LANG['form_type_file'] = '附件上傳';

// 預置模板引導頁
$_LANG['form_template_title'] = '新增表單';
$_LANG['form_template_intro'] = '從預置模板一鍵產生表單與整套欄位，也可以先建立空白表單再逐個新增欄位。';
$_LANG['form_template_blank'] = '建立空白表單';
$_LANG['form_template_blank_desc'] = '只建表單框架，名稱、英文標記與提交行為稍後填寫，欄位在欄位頁逐個新增。';
$_LANG['form_template_blank_fields'] = '欄位自由定義';
$_LANG['form_template_blank_btn'] = '立即建立';
$_LANG['form_template_use_btn'] = '一鍵使用';
$_LANG['form_template_field_badge'] = '含 d% 個欄位';
$_LANG['form_template_created'] = '表單「d%」已建立，請繼續新增欄位';

// 預置模板（與 config/form.php 一致）
$_LANG['form_template_contact'] = '業務諮詢';
$_LANG['form_template_quote'] = '產品詢價';
$_LANG['form_template_feedback'] = '線上留言';
$_LANG['form_template_cooperation'] = '合作加盟';
$_LANG['form_template_resume'] = '履歷投遞';
