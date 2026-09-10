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
 * product 栏目模块声明式路由
 *
 * 按当前选中 column 风格规则展开为 declared 条目（列表 / 分类 / 详情）。
 * 控制器：ProductController（index 列表 + 分类，show 详情）。
 */

use Dou\Core\Web\Routing\Route;
use Dou\Front\Controller\Product\ProductController;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::column('product', ProductController::class);
