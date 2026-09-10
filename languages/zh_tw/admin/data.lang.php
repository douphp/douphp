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

// 資料擴充
$_LANG['data'] = '內容片段';
$_LANG['data_list'] = '片段列表';
$_LANG['data_create'] = '新增內容片段';
$_LANG['data_edit'] = '編輯片段';
$_LANG['data_copy'] = '複製上一個項目的內容片段';
$_LANG['data_lock'] = '鎖定';
$_LANG['data_unlock'] = '解除鎖定';
$_LANG['data_all'] = '顯示所有片段';
$_LANG['data_all_hidden'] = '隱藏已分組片段';
$_LANG['data_info'] = '片段資訊';
$_LANG['data_transform'] = '資料遷移';
$_LANG['data_sync_from_template'] = '同步模板資料';
$_LANG['data_sync_result'] = '同步完成：掃描 {scanned} 項，新增 {inserted} 項，跳過已存在 {skipped} 項';

// 欄位
$_LANG['data_theme'] = '關聯模板';
$_LANG['data_parent_code'] = '分組';
$_LANG['data_module'] = '所屬模組';
$_LANG['data_item_id'] = '所屬項目';
$_LANG['data_name'] = '片段名稱';
$_LANG['data_code'] = '唯一標記';
$_LANG['data_image'] = '圖片內容';
$_LANG['data_text'] = '文字內容';
$_LANG['data_link'] = '連結';
$_LANG['data_is_class'] = '設定為分組';
$_LANG['data_is_locked'] = '是否鎖定';

// Banner
$_LANG['data_banner'] = '橫幅廣告';

// 小程式
$_LANG['data_miniprogram'] = '小程式數據';

// 備註
$_LANG['data_class_cue'] = '設定為分組後，可以在當前片段下新增子片段，前台呼叫標籤：<em class="copytext">' . htmlspecialchars('<!-- {foreach from=$data.唯一標記.child item=item} -->{$item.name}<!-- {/foreach} -->', ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</em>';
$_LANG['data_name_cue'] = '前台呼叫標籤：<em class="copytext">{$data.唯一標記.name}</em>';
$_LANG['data_code_cue'] = '唯一標記不能為空且只能使用小寫字母、數字、下底線，前台呼叫標籤為：<em class="copytext">{$data.唯一標記.code}</em>，建議手動輸入便於記憶的英文單字或拼音作為標記';
$_LANG['data_image_cue'] = '前台呼叫標籤：<em class="copytext">{$data.唯一標記.image}</em>';
$_LANG['data_text_cue'] = '前台呼叫標籤：<em class="copytext">{$data.唯一標記.text}</em>';
$_LANG['data_link_cue'] = '前台呼叫標籤：<em class="copytext">{$data.唯一標記.link}</em>';
$_LANG['data_transform_cue'] = '將「內容碎片、內容盒子」，兩個模組的資料遷移到「內容片段」模組，後續「內容碎片、內容盒子」兩個模組將陸續停用！';

// 提示訊息
$_LANG['data_add_succes'] = '新增內容片段成功';
$_LANG['data_edit_succes'] = '編輯內容片段成功';
$_LANG['data_delete'] = '刪除內容片段';
$_LANG['data_code_wrong'] = '唯一標記不能為空且只能使用小寫字母、數字、下底線';
$_LANG['data_code_existed'] = '唯一標記已經存在，請重新輸入';
$_LANG['data_transform_succes'] = '已將「內容碎片、內容盒子」，兩個模組的資料成功遷移到「內容片段」模組！';
