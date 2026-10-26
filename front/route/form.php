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

/**
 * form 表单声明式路由
 *
 * 公开入口：
 *   - 填写页：/form/{slug}（以表单别名为路径段，替代旧的 /form/{id}）
 *   - 根路径：/form 列表已退役，index() 直接跳首页，保留旧链接不 404
 *   - 提交动作：/form/submit（FormRequest 验签 + 入库）
 *   - 成功提示页：/form/{slug}/success（后台未配置 success_url 时的提交落点）
 *
 * 详情不用 Route::simple 展开：simple 的详情段是纯数字主键，而表单对外的稳定标识
 * 是后台必填且唯一的 slug（FormFormRequest 校验 `^[a-z][a-z0-9_]{0,59}$`）。
 * 提交仍以表单里的隐藏域 form_id 为准，URL 形态不影响入库。
 */

use Dou\Core\Web\Routing\Route;
use Dou\Front\Controller\Form\FormController;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::get('form', FormController::class, 'index', 'form', 'form.index');
Route::get('form/{slug}', FormController::class, 'show', 'form', 'form.show', array('slug' => '[a-z][a-z0-9_]{0,59}'));
Route::get('form/{slug}/success', FormController::class, 'success', 'form', 'form.success', array('slug' => '[a-z][a-z0-9_]{0,59}'));
Route::post('form/submit', FormController::class, 'submit', 'form', 'form.submit');
