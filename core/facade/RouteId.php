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

namespace Dou\Core\Facade;

use Dou\Core\Foundation\Facade\StaticFacade;
use Dou\Core\Web\Routing\RouteIdValidator;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * RouteId 静态门面：底层为 {@see RouteIdValidator} 容器单例，用于控制器入口校验路由 ID 合法性。
 *
 * 用法：
 *   use Dou\Core\Facade\RouteId;
 *   $catId = RouteId::category('article_category', $catIdRaw, $slug);
 *   $id    = RouteId::column('article', $id, $categorySlug, $slug);
 *   $id    = RouteId::single('team', $id);
 *   $id    = RouteId::page($id, $slug);
 *
 * 返回值约定：-1 非法或未命中；0 分类列表（仅 *_category 且 id=0/无参数）；>0 合法内容 ID。
 *
 * @method static int category(string $module, string $categoryId = '', string $slug = '', string $year = '', string $month = '')
 * @method static int column(string $module, string $id = '', string $categorySlug = '', string $slug = '', string $year = '', string $month = '')
 * @method static int single(string $module, string $id = '')
 * @method static int page(string $id = '', string $slug = '')
 */
class RouteId extends StaticFacade
{
    /**
     * 容器中以 RouteIdValidator FQCN 为 key 注册。
     *
     * @return string
     */
    protected static function getAccessor()
    {
        return RouteIdValidator::class;
    }
}
