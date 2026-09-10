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

namespace Dou\Admin\Middleware;

use Dou\Admin\Service\Authorization\AdminGate;
use Dou\Core\Foundation\Middleware\MiddlewareInterface;
use Dou\Core\Web\Http\HttpResponseException;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台模块权限中间件
 *
 * 检查当前管理员是否有权限访问当前模块（request()->routeModule()）。
 * 超级管理员（type != defined）直接放行；defined 类型按 action_list 白名单判定。
 * 依赖 AuthMiddleware 已先行写入登录管理员信息（auth('admin')->user()）。
 */
class PermissionMiddleware implements MiddlewareInterface
{
    /** @var AdminGate */
    private $gate;

    /**
     * @param AdminGate $gate
     */
    public function __construct(AdminGate $gate)
    {
        $this->gate = $gate;
    }

    /**
     * @param callable $next
     * @return mixed
     */
    public function handle($next)
    {
        $admin = (array) auth('admin')->user();
        if (empty($admin)) {
            throw new HttpResponseException(redirect(route('admin.login')));
        }

        $request = request();
        $cur = (string) $request->routeModule();
        if ($cur === '') {
            throw new HttpResponseException(redirect(ROOT_URL . ADMIN_DIR));
        }

        $action = $request->routeAction() !== '' ? (string) $request->routeAction() : 'index';
        $targetId = $request->integer('id', 0);

        if (!$this->gate->canAccess($admin, $cur, $action, $targetId)) {
            throw new HttpResponseException(redirect(ROOT_URL . ADMIN_DIR));
        }

        return $next();
    }
}
