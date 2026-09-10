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
 * tool 后台声明式路由
 *
 * 声明 ?route=tool/<action> 形态：站长工具集（目录检查 / URL 替换 / 自定义后台 / 排序 / 在线
 * 编辑器等）。该控制器无 index 方法，所有动作都不是根。
 */

use Dou\Admin\Controller\Tool\ToolController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::group('tool', ToolController::class)->name('admin.')
    ->prefix('tool')
    ->root('__noop__')
    ->get(['directory_check', 'replace_url', 'custom_admin_dir'])
    ->post(['store', 'sort', 'change_field', 'editor']);

// 命名路由 manifest 脚本：含登录页在内的每个后台页都要加载（与 captcha 同属登录前可取的子资源），
// 故豁免 auth（登录页未鉴权也须能取，否则 <script src> 被 302 到登录页 HTML、MIME 报错）、
// permission（受限管理员无 tool 权限也须能取）与 workspace（仅为视图装配工作台数据，JS 响应不需要）。
// 内容仅为 name => pattern 结构映射（非敏感数据），各后台动作仍由 auth/permission/csrf 中间件守护。
Route::get('tool/routes_js', ToolController::class, 'routes_js', 'tool', 'admin.tool.routes_js')
    ->withoutMiddleware(['auth', 'permission', 'workspace']);

// 语言包 manifest 脚本（window.__douLang = {...};）：与 routes_js 同样豁免 auth/permission/workspace，
// 登录页等未鉴权页面也要能取到译串脚本，否则 <script src> 被 302 到登录页 HTML 触发 MIME 报错。
Route::get('tool/lang_js', ToolController::class, 'lang_js', 'tool', 'admin.tool.lang_js')
    ->withoutMiddleware(['auth', 'permission', 'workspace']);
