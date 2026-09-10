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

// 数据扩展
$_LANG['data'] = '内容片段';
$_LANG['data_list'] = '片段列表';
$_LANG['data_create'] = '添加内容片段';
$_LANG['data_edit'] = '编辑片段';
$_LANG['data_copy'] = '复制上一个项目的内容片段';
$_LANG['data_lock'] = '锁定';
$_LANG['data_unlock'] = '解除锁定';
$_LANG['data_all'] = '显示所有片段';
$_LANG['data_all_hidden'] = '隐藏已分组片段';
$_LANG['data_info'] = '片段信息';
$_LANG['data_transform'] = '数据迁移';
$_LANG['data_sync_from_template'] = '同步模板数据';
$_LANG['data_sync_result'] = '同步完成：扫描 {scanned} 项，新增 {inserted} 项，跳过已存在 {skipped} 项';

// 字段
$_LANG['data_theme'] = '关联模板';
$_LANG['data_parent_code'] = '分组';
$_LANG['data_module'] = '所属模块';
$_LANG['data_item_id'] = '所属项目';
$_LANG['data_name'] = '片段名称';
$_LANG['data_code'] = '唯一标记';
$_LANG['data_image'] = '图片内容';
$_LANG['data_text'] = '文字内容';
$_LANG['data_link'] = '链接';
$_LANG['data_is_class'] = '设置为分组';
$_LANG['data_is_locked'] = '是否锁定';

// Banner
$_LANG['data_banner'] = '横幅广告';

// 小程序
$_LANG['data_miniprogram'] = '小程序数据';

// 备注
$_LANG['data_class_cue'] = '设置为分组后，可以在当前片段下添加子片段，前台调用标签：<em class="copytext">' . htmlspecialchars('<!-- {foreach from=$data.唯一标记.child item=item} -->{$item.name}<!-- {/foreach} -->', ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</em>';
$_LANG['data_name_cue'] = '前台调用标签：<em class="copytext">{$data.唯一标记.name}</em>';
$_LANG['data_code_cue'] = '唯一标记不能为空且只能使用小写字母、数字、下划线，前台调用标签为：<em class="copytext">{$data.唯一标记.code}</em>，建议手动输入便于记忆的英文单词或拼音作为标记';
$_LANG['data_image_cue'] = '前台调用标签：<em class="copytext">{$data.唯一标记.image}</em>';
$_LANG['data_text_cue'] = '前台调用标签：<em class="copytext">{$data.唯一标记.text}</em>';
$_LANG['data_link_cue'] = '前台调用标签：<em class="copytext">{$data.唯一标记.link}</em>';
$_LANG['data_transform_cue'] = '将“内容碎片、内容盒子”，两个模块的数据迁移到“内容片段”模块，后续“内容碎片、内容盒子”两个模块将陆续停用！';

// 提示信息
$_LANG['data_add_succes'] = '添加内容片段成功';
$_LANG['data_edit_succes'] = '编辑内容片段成功';
$_LANG['data_delete'] = '删除内容片段';
$_LANG['data_code_wrong'] = '唯一标记不能为空且只能使用小写字母、数字、下划线';
$_LANG['data_code_existed'] = '唯一标记已经存在，请重新输入';
$_LANG['data_transform_succes'] = '已将“内容碎片、内容盒子”，两个模块的数据成功迁移到“内容片段”模块！';
