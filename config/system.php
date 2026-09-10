<?php

/**
 * 系统恒定常量（区别于 config/module.php 的"用户可调模块账本"）。
 *
 * 本文件承载框架层固定的模块短名清单，不随模块安装/卸载改写，由
 * {@see \Dou\Core\Service\System\SystemConstantsReader} 读取后灌入 Config 的
 * `system.*` 命名空间（与 `setting.*` 的模块账本分离）。
 */

return [
    // 前台路由层固定内建模块（不依赖 module.all_module 的启用判定），仅服务前台路由判定，
    // 含 captcha/sitemap/llms 这些非小程序端点
    'front_fixed_module' => ['index', 'page', 'search', 'captcha', 'sitemap', 'llms'],

    // 小程序内建模块（始终注册进 app.json，且对应模块需有 API 控制器）。
    // 与 front_fixed_module 完全独立：小程序无 captcha/sitemap/llms 页面
    'miniprogram_builtin_module' => ['index', 'page', 'search'],

    // 前台保留 URL 首段（不可被模块短名占用）
    'reserved_first_segment' => ['llms', 'sitemap', 'captcha', 'search', 'category', 'index', 'plugin'],

    // 小程序专属页（无对应业务模块，直接登记进 app.json）
    'miniprogram_extra_page' => ['pages/debug/debug'],

    // 不参与小程序 app.json 生成的 single 模块（系统型 / 容器型，无小程序页面）
    'miniprogram_no_handle' => ['plugin', 'box', 'fragment', 'language', 'data', 'weixin', 'attribute', 'email'],

    // 不进前台主导航目标下拉的 single 模块（系统型 / 容器型，无前台分类入口）
    'nav_hidden_single' => ['plugin', 'box', 'fragment', 'language'],

    // 后台菜单 / 工作台 / 首页统计隐藏的系统级 single 模块
    'admin_hidden_single' => ['box', 'fragment', 'language'],
];
