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

// 雲端服務
$_LANG['cloud'] = '雲端服務';
$_LANG['cloud_buy_vip'] = '開通VIP會員';
$_LANG['cloud_admin_home'] = '返回管理中心';
$_LANG['cloud_handle'] = '線上安裝';
$_LANG['cloud_update_home'] = '返回更新首頁';
$_LANG['cloud_module'] = '模組';
$_LANG['cloud_module_home'] = '返回模組管理中心';
$_LANG['cloud_plugin'] = '外掛';
$_LANG['cloud_plugin_enable'] = '啟用外掛';
$_LANG['cloud_plugin_home'] = '返回外掛管理中心';
$_LANG['cloud_theme'] = '模板';
$_LANG['cloud_theme_enable'] = '啟用模板';
$_LANG['cloud_theme_home'] = '返回模板管理中心';
$_LANG['cloud_mobile_theme_home'] = '返回手機版模板管理';
$_LANG['cloud_miniprogram'] = '小程式';
$_LANG['cloud_miniprogram_home'] = '返回小程式管理中心';
$_LANG['cloud_system'] = '系統';
$_LANG['cloud_handle_success'] = '安裝成功';
$_LANG['cloud_title_install'] = '正在安裝';
$_LANG['cloud_title_install_for_theme'] = '正在安裝模板 d% 所需的';
$_LANG['cloud_title_update'] = '正在更新';
$_LANG['cloud_order'] = '購買';
$_LANG['cloud_order_submit'] = '送出訂單';

// 提示訊息
$_LANG['cloud_frame_cue'] = '這個功能需要 iframe 的支援。您可能禁止了 iframe 的顯示，或您的瀏覽器不支援此功能。';
$_LANG['cloud_pay_fail'] = '付款失敗';
$_LANG['cloud_pay_success'] = '付款成功';
$_LANG['cloud_closed'] = '系統已關閉線上升級功能，請先開啟！';
$_LANG['cloud_connect_failed'] = '無法連接雲端服務，請稍後重試或檢查雲端服務地址設定。';
$_LANG['cloud_api_base_missing'] = '未設定雲端服務介面地址，無法使用線上安裝等功能。';

// 解壓縮
$_LANG['cloud_unzip_ing'] = '正在解壓縮安裝包…';
$_LANG['cloud_unzip_wrong'] = '壓縮包解壓失敗';
$_LANG['cloud_unzip_missing'] = '未找到已下載的安裝包檔案，請關閉本頁後重新發起安裝或更新。';

// 下載
$_LANG['cloud_down_ing_0'] = '正在從 ';
$_LANG['cloud_down_ing_1'] = ' 下載安裝包…';
$_LANG['cloud_down_wrong'] = '下載失敗';
$_LANG['cloud_local_zip_missing'] = '未在 cache 目錄找到本地安裝包，請將模組壓縮包（與模組標識同名的 .zip）放到 cache 後再試。';
$_LANG['cloud_down_upstream_not_found'] = '雲端未找到該安裝包（可能版本或編號不存在），請確認後再試。';
$_LANG['cloud_down_upstream_unavailable'] = '下載服務暫時不可用或上游鏡像無法存取，請稍後再試。';
$_LANG['cloud_down_upstream_misconfigured'] = '雲端下載服務未正確設定上游地址，請聯絡管理員。';

// 安裝
$_LANG['cloud_install_ing'] = '正在安裝 ';
$_LANG['cloud_install_0'] = '安裝 ';
$_LANG['cloud_install_1'] = ' 成功';
$_LANG['cloud_install_next'] = '即將安裝下一個模組';
$_LANG['cloud_install_theme'] = '即將安裝模板';
$_LANG['cloud_below_mini_version_support'] = '您的系統版本過低，請升級後重新安裝！';
$_LANG['cloud_install_repeat'] = '已經存在，不要重複安裝';
$_LANG['cloud_sql_repeat'] = '資料表已經存在於資料庫中，不要重複安裝';
$_LANG['cloud_sql_wrong'] = '資料庫無法匯入、';
$_LANG['cloud_systemfile_wrong'] = '無法修改系統設定檔案';

// 線上安裝多步流程（cloud/install）
$_LANG['cloud_install_retry'] = '重新安裝';
$_LANG['cloud_install_session_missing'] = '安裝會話已過期或不存在，請返回安裝入口重新發起';
$_LANG['cloud_install_request_failed'] = '安裝步驟請求失敗（網路中斷、逾時或服務端返回了非 JSON）。解壓大包時若較慢，請在伺服器上適當提高 max_execution_time 與可用記憶體。';
$_LANG['cloud_install_step_failed_generic'] = '安裝步驟失敗，請稍後重試或查看伺服器與 PHP 錯誤日誌。';

// 線上安裝權限矩陣（與 download/install-resolve 的 auth_status 對齊）
$_LANG['cloud_install_login_required'] = '請先設定雲端帳號後再下載安裝。';
$_LANG['cloud_install_login_cloud_account'] = '設定雲端帳號';
$_LANG['cloud_install_not_purchased'] = '該付費擴充尚未購買，請先購買後再安裝。';
$_LANG['cloud_install_buy_extend'] = '購買擴充';
$_LANG['cloud_install_vip_required'] = '該擴充為 VIP 專享，您當前沒有 VIP，請先購買 VIP。';
$_LANG['cloud_install_buy_vip'] = '購買 VIP';
$_LANG['cloud_install_vip_expired'] = '您的 VIP 已過期，請續費後再安裝 VIP 專享擴充。';
$_LANG['cloud_install_renew_vip'] = '續費 VIP';
$_LANG['cloud_install_resolve_unavailable'] = '雲端服務暫時不可用，請稍後重試。';

// 升級
$_LANG['cloud_update'] = '系統更新';
$_LANG['cloud_update_0'] = '更新 ';
$_LANG['cloud_update_next'] = '即將更新下一個模組';

// 雲端帳戶設定
$_LANG['cloud_account_title'] = 'Dou雲端帳戶';
$_LANG['cloud_account'] = '設定雲端帳戶';
$_LANG['cloud_account_cue'] = '您的雲端帳戶沒有設定，或者設定不正確，請檢查';
$_LANG['cloud_account_intro'] = '設定雲端帳戶後，可使用線上安裝模組、模板等雲端服務功能';
$_LANG['cloud_account_user'] = '雲端帳戶使用者名稱';
$_LANG['cloud_account_password'] = '雲端帳戶密碼';
$_LANG['cloud_account_success'] = '設定雲端帳戶成功';
$_LANG['cloud_account_register'] = '立即註冊';
$_LANG['cloud_account_register_0'] = '沒有帳戶？';
$_LANG['cloud_account_seted'] = '已經設定雲端帳戶';
$_LANG['cloud_account_reset'] = '重新設定';
$_LANG['cloud_account_user_wrong'] = '雲端帳戶使用者名稱應為郵箱或手機號，您輸入格式不正確或者為空';
$_LANG['cloud_account_wrong'] = '雲端帳戶使用者名稱或密碼輸入不正確';
$_LANG['cloud_account_clean'] = '清空雲端帳戶';
$_LANG['cloud_account_clean_success'] = '清空雲端帳戶成功';

// 去版權
$_LANG['cloud_copyright'] = '去版權';
$_LANG['cloud_copyright_success'] = '前台版權去除成功！';
$_LANG['cloud_copyright_no_vip'] = '該帳號或者該網域名稱沒有開通VIP會員！';

// 雲端擴充列表（inc/cloud_extend_list.tpl）
$_LANG['cloud_extend_filter_all'] = '全部';
$_LANG['cloud_extend_filter_free_theme'] = '免費模板';
$_LANG['cloud_extend_price_label'] = '費用：';
$_LANG['cloud_extend_author_label'] = '作者：';
$_LANG['cloud_extend_need_module_label'] = '支援模組：';
$_LANG['cloud_extend_preview_theme'] = '預覽模板';
$_LANG['cloud_extend_download_count'] = '下載量：';
$_LANG['cloud_extend_miniprogram_badge'] = '適配小程式';
$_LANG['cloud_extend_update_time_label'] = '更新時間：';
$_LANG['cloud_extend_min_version_label'] = '最低版本要求：';
$_LANG['cloud_extend_detail'] = '詳細資訊';
