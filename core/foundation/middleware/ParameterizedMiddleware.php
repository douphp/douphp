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

namespace Dou\Core\Foundation\Middleware;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 可参数化中间件接口
 *
 * 支持「路由级参数覆盖」的中间件实现本接口；{@see \Dou\Core\Web\Routing\MiddlewareRegistry}
 * 在该路由声明了对应别名参数（如 `permission:article.edit_extended`、`throttle:5,60`）时，
 * 为该路由**新建独占实例**并调用 {@see setRouteParameters} 注入参数，不污染共享默认实例。
 *
 * 参数语义由各中间件自行解释（位置参数数组，全为字符串），例：
 *   - PermissionMiddleware：$params[0] = 权限节点 id
 *   - ThrottleMiddleware：  $params[0] = max 次数，$params[1] = window 秒数
 *   - UserAuthMiddleware：  $params[0] = 鉴权模式 public|optional|required
 */
interface ParameterizedMiddleware
{
    /**
     * 注入路由级参数（位置参数数组，全为字符串）。
     *
     * @param string[] $params
     * @return void
     */
    public function setRouteParameters(array $params);
}
