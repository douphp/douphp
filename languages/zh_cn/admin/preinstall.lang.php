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

// 预置模块（打包方在升级包内预置的模块推广入口）
$_LANG['preinstall'] = '预置模块';
$_LANG['preinstall_cue'] = '本模块尚未安装，安装后此预置入口将自动消失，由模块菜单接管。';
$_LANG['preinstall_feature_title'] = '主要功能';
$_LANG['preinstall_go_module'] = '前往模块管理';
$_LANG['preinstall_install'] = '安装';
$_LANG['preinstall_hide'] = '隐藏此预置项';
$_LANG['preinstall_hide_confirm'] = '隐藏后该预置入口将从左侧菜单中消失，确定隐藏吗？';
$_LANG['preinstall_hide_success'] = '预置项已隐藏';

// 预置内容：ai（AI 应用）
$_LANG['preinstall_ai_name'] = 'AI 应用';
$_LANG['preinstall_ai_intro'] = 'AI 应用为站点提供整站 AI 能力中心，统一管理模型与供应商、API 密钥、调用日志与生成任务，并向商品、文章等内容模块提供智能生成端点。';
$_LANG['preinstall_ai_feature_1_title'] = '字段润色';
$_LANG['preinstall_ai_feature_1_desc'] = '在内容编辑页对单个字段一键调用 AI 润色与改写，支持实时预览后再确认写入。';
$_LANG['preinstall_ai_feature_2_title'] = '内容翻译';
$_LANG['preinstall_ai_feature_2_desc'] = '内置多语言翻译端点，可将标题、正文等内容一键翻译为目标语言，服务多语言站点。';
$_LANG['preinstall_ai_feature_3_title'] = '自动内容生成';
$_LANG['preinstall_ai_feature_3_desc'] = '支持整表单生成，按字段自动补全商品、文章等内容，减少手工录入。';
$_LANG['preinstall_ai_feature_4_title'] = '图像生成';
$_LANG['preinstall_ai_feature_4_desc'] = '通过异步生成任务调用图像模型产出配图，任务与产出可查可删，便于批量处理。';
$_LANG['preinstall_ai_feature_5_title'] = 'Banner 生成';
$_LANG['preinstall_ai_feature_5_desc'] = '内置横幅风格预设与参考图用法，按首页 Banner 的构图与尺寸规范归一化输出素材。';
$_LANG['preinstall_ai_feature_6_title'] = '批量生成内容';
$_LANG['preinstall_ai_feature_6_desc'] = '提供批量生成端点，可一次为多条内容或字段触发 AI 生成，受并发与额度约束。';

// 预置内容：form（表单）
$_LANG['preinstall_form_name'] = '表单';
$_LANG['preinstall_form_intro'] = '表单模块提供可视化表单搭建能力：后台创建表单、按需添加字段并配置校验，前台以嵌入代码接入，提交数据集中管理，可支撑留言、询价、合作加盟、简历投递等场景。';
$_LANG['preinstall_form_feature_1_title'] = '自定义字段';
$_LANG['preinstall_form_feature_1_desc'] = '单行文本、多行文本、下拉、单选、复选、开关、日期、省市区与上传等字段类型可自由组合，栅格宽度决定桌面端并排布局。';
$_LANG['preinstall_form_feature_2_title'] = '校验与填写控制';
$_LANG['preinstall_form_feature_2_desc'] = '必填、长度与数值区间、邮箱 / 网址 / 手机号 / 身份证 / 邮编 / 正则等规则可按字段配置，减少无效提交。';
$_LANG['preinstall_form_feature_3_title'] = '提交数据管理';
$_LANG['preinstall_form_feature_3_desc'] = '前台提交集中入库，后台可查看提交内容、来源页面与 IP，支持未读 / 已处理标记与一键全部处理。';
$_LANG['preinstall_form_feature_4_title'] = '防刷与权限';
$_LANG['preinstall_form_feature_4_desc'] = '可开启验证码、限制仅登录会员提交并设置同 IP 每小时上限，上传字段强制要求登录。';
$_LANG['preinstall_form_feature_5_title'] = '嵌入任意模板';
$_LANG['preinstall_form_feature_5_desc'] = '表单一键生成可复制的 HTML 嵌入代码，粘贴到任意前台模板即可渲染，无需改动控制器。';
$_LANG['preinstall_form_feature_6_title'] = '预置表单模板';
$_LANG['preinstall_form_feature_6_desc'] = '内置业务咨询、产品询价、在线留言、合作加盟与简历投递模板，一键生成表单及整套字段。';

// 预置内容：language（多语言）
$_LANG['preinstall_language_name'] = '多语言';
$_LANG['preinstall_language_intro'] = '多语言模块为站点开启多语言能力：后台维护语言条目，按语言维护站点信息与内容字段，并可为语言启用独立货币，前台提供语言切换入口。';
$_LANG['preinstall_language_feature_1_title'] = '语言条目管理';
$_LANG['preinstall_language_feature_1_desc'] = '新增、编辑与删除语言条目，绑定对应语言包并指定系统语言，决定站点可切换的语言范围。';
$_LANG['preinstall_language_feature_2_title'] = '站点信息多语言';
$_LANG['preinstall_language_feature_2_desc'] = '站点名称、标题、关键字、描述、Logo 与联系方式按语言分别维护，前台随当前语言输出。';
$_LANG['preinstall_language_feature_3_title'] = '内容字段多语言';
$_LANG['preinstall_language_feature_3_desc'] = '在内容编辑页为字段维护各语言版本，语言抽屉内直接录入与切换查看，保存后按当前语言读取。';
$_LANG['preinstall_language_feature_4_title'] = '独立货币';
$_LANG['preinstall_language_feature_4_desc'] = '可为语言启用独立货币并配置汇率与价格小数位，下单时把汇率快照进订单，便于多币种报价。';
$_LANG['preinstall_language_feature_5_title'] = '前台语言切换';
$_LANG['preinstall_language_feature_5_desc'] = '主题自带语言切换菜单，访客可在各语言版本间直接切换，无需手工拼接地址。';
$_LANG['preinstall_language_feature_6_title'] = '可搭配 AI 自动翻译';
$_LANG['preinstall_language_feature_6_desc'] = 'AI 应用中启用翻译应用后，多语言字段编辑工具条出现一键翻译，把原文自动译入当前语言并直接保存，无需手工录入。';
