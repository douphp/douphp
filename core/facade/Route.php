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
use Dou\Core\Web\Routing\DelegatingRouter;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * Route 入站静态门面：底层为 {@see DelegatingRouter} 容器单例。
 *
 * 承担**入站**路由职责：
 *   - 注入三端具体 Router 作为 delegate（`Route::setDelegate(...)`）
 *   - 触发分发（`Route::dispatch()`）
 *   - 查询当前路由状态（`Route::current()`）
 *
 * 出站 URL 生成由 {@see Url} 门面与 helper `route()` 承担，本门面不提供。
 *
 * @method static DelegatingRouter setDelegate(object $delegate)
 * @method static mixed dispatch()
 * @method static array current()
 */
class Route extends StaticFacade
{
    /**
     * 容器中以 DelegatingRouter FQCN 为 key 注册。
     *
     * @return string
     */
    protected static function getAccessor()
    {
        return DelegatingRouter::class;
    }
}
