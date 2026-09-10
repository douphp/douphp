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
 * page 单页模块声明式路由
 *
 * 按当前选中 page 风格规则展开为 declared 条目（详情仅一种动作 show）。
 * 风格选项包括 suffix（{slug}.html）、prefixed（page/{slug}）、id（page/{id:\d+}）等，
 * 实际生效形态以 site.route_page 配置为准。控制器：PageController::show。
 */

use Dou\Core\Web\Routing\Route;
use Dou\Front\Controller\Page\PageController;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::page(PageController::class);
