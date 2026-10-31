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
 * Release Date: 2026-10-14
 */

// 預置模組（打包方在升級包內預置的模組推廣入口）
$_LANG['preinstall'] = '預置模組';
$_LANG['preinstall_cue'] = '本模組尚未安裝，安裝後此預置入口將自動消失，由模組選單接管。';
$_LANG['preinstall_feature_title'] = '主要功能';
$_LANG['preinstall_go_module'] = '前往模組管理';
$_LANG['preinstall_install'] = '安裝';
$_LANG['preinstall_hide'] = '隱藏此預置項';
$_LANG['preinstall_hide_confirm'] = '隱藏後該預置入口將從左側選單中消失，確定隱藏嗎？';
$_LANG['preinstall_hide_success'] = '預置項已隱藏';

// 預置內容：ai（AI 應用）
$_LANG['preinstall_ai_name'] = 'AI 應用';
$_LANG['preinstall_ai_intro'] = 'AI 應用為站點提供整站 AI 能力中心，統一管理模型與供應商、API 金鑰、呼叫日誌與生成任務，並向商品、文章等內容模組提供智慧生成端點。';
$_LANG['preinstall_ai_feature_1_title'] = '欄位潤色';
$_LANG['preinstall_ai_feature_1_desc'] = '在內容編輯頁對單個欄位一鍵呼叫 AI 潤色與改寫，支援即時預覽後再確認寫入。';
$_LANG['preinstall_ai_feature_2_title'] = '內容翻譯';
$_LANG['preinstall_ai_feature_2_desc'] = '內建多語言翻譯端點，可將標題、正文等內容一鍵翻譯為目標語言，服務多語言站點。';
$_LANG['preinstall_ai_feature_3_title'] = '自動內容生成';
$_LANG['preinstall_ai_feature_3_desc'] = '支援整表單生成，按欄位自動補全商品、文章等內容，減少手工錄入。';
$_LANG['preinstall_ai_feature_4_title'] = '圖像生成';
$_LANG['preinstall_ai_feature_4_desc'] = '透過非同步生成任務呼叫圖像模型產出配圖，任務與產出可查可刪，便於批次處理。';
$_LANG['preinstall_ai_feature_5_title'] = 'Banner 生成';
$_LANG['preinstall_ai_feature_5_desc'] = '內建橫幅風格預設與參考圖用法，按首頁 Banner 的構圖與尺寸規範歸一化輸出素材。';
$_LANG['preinstall_ai_feature_6_title'] = '批次生成內容';
$_LANG['preinstall_ai_feature_6_desc'] = '提供批次生成端點，可一次為多條內容或欄位觸發 AI 生成，受並發與額度約束。';

// 預置內容：form（表單）
$_LANG['preinstall_form_name'] = '表單';
$_LANG['preinstall_form_intro'] = '表單模組提供視覺化表單建置能力：後台建立表單、依需求新增欄位並設定驗證，前台以嵌入程式碼接入，提交資料集中管理，可支撐留言、詢價、合作加盟、履歷投遞等場景。';
$_LANG['preinstall_form_feature_1_title'] = '自訂欄位';
$_LANG['preinstall_form_feature_1_desc'] = '單行文字、多行文字、下拉、單選、複選、開關、日期、省市區與上傳等欄位類型可自由組合，柵格寬度決定桌面端並排佈局。';
$_LANG['preinstall_form_feature_2_title'] = '驗證與填寫控制';
$_LANG['preinstall_form_feature_2_desc'] = '必填、長度與數值區間、信箱 / 網址 / 手機號 / 身分證 / 郵遞區號 / 正規表示式等規則可依欄位設定，減少無效提交。';
$_LANG['preinstall_form_feature_3_title'] = '提交資料管理';
$_LANG['preinstall_form_feature_3_desc'] = '前台提交集中入庫，後台可檢視提交內容、來源頁面與 IP，支援未讀 / 已處理標記與一鍵全部處理。';
$_LANG['preinstall_form_feature_4_title'] = '防刷與權限';
$_LANG['preinstall_form_feature_4_desc'] = '可啟用驗證碼、限制僅登入會員提交並設定同 IP 每小時上限，上傳欄位強制要求登入。';
$_LANG['preinstall_form_feature_5_title'] = '嵌入任意模板';
$_LANG['preinstall_form_feature_5_desc'] = '表單一鍵產生可複製的 HTML 嵌入程式碼，貼到任意前台模板即可呈現，無需修改控制器。';
$_LANG['preinstall_form_feature_6_title'] = '預置表單模板';
$_LANG['preinstall_form_feature_6_desc'] = '內建業務諮詢、產品詢價、線上留言、合作加盟與履歷投遞模板，一鍵產生表單及整套欄位。';

// 預置內容：language（多語言）
$_LANG['preinstall_language_name'] = '多語言';
$_LANG['preinstall_language_intro'] = '多語言模組為站點開啟多語言能力：後台維護語言條目，依語言維護站點資訊與內容欄位，並可為語言啟用獨立貨幣，前台提供語言切換入口。';
$_LANG['preinstall_language_feature_1_title'] = '語言條目管理';
$_LANG['preinstall_language_feature_1_desc'] = '新增、編輯與刪除語言條目，綁定對應語言包並指定系統語言，決定站點可切換的語言範圍。';
$_LANG['preinstall_language_feature_2_title'] = '站點資訊多語言';
$_LANG['preinstall_language_feature_2_desc'] = '站點名稱、標題、關鍵字、描述、Logo 與聯絡方式依語言分別維護，前台隨目前語言輸出。';
$_LANG['preinstall_language_feature_3_title'] = '內容欄位多語言';
$_LANG['preinstall_language_feature_3_desc'] = '在內容編輯頁為欄位維護各語言版本，語言抽屜內直接錄入與切換檢視，儲存後依目前語言讀取。';
$_LANG['preinstall_language_feature_4_title'] = '獨立貨幣';
$_LANG['preinstall_language_feature_4_desc'] = '可為語言啟用獨立貨幣並設定匯率與價格小數位，下單時把匯率快照進訂單，便於多幣別報價。';
$_LANG['preinstall_language_feature_5_title'] = '前台語言切換';
$_LANG['preinstall_language_feature_5_desc'] = '主題自帶語言切換選單，訪客可在各語言版本間直接切換，無需手動拼接網址。';
$_LANG['preinstall_language_feature_6_title'] = '可搭配 AI 自動翻譯';
$_LANG['preinstall_language_feature_6_desc'] = 'AI 應用中啟用翻譯應用後，多語言欄位編輯工具條出現一鍵翻譯，將原文自動譯入目前語言並直接儲存，無需手動錄入。';
