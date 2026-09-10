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

use Dou\Admin\Service\Workspace\UpdateBadgeBuilder;
use Dou\Admin\Service\Workspace\WorkspaceBuilder;
use Dou\Core\Foundation\Middleware\MiddlewareInterface;
use Dou\Core\Web\Template\DouView;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台工作台视图变量注入中间件
 *
 * 向后台布局模板注入 `global_admin` / `workspace` / `unum` 变量。
 * 挂载链上位于 {@see AuthMiddleware} 与 {@see PermissionMiddleware}
 * 之后——确保「登录态已恢复且模块准入已通过」的请求才注入；登录页 / 越权重定向均不会进入本层。
 *
 * 当前管理员上下文从 `auth('admin')->user()` 读取（AuthMiddleware 已完成注入）；
 * 上下文为空（如登录页放行）或视图引擎不可用时静默跳过。
 */
class AdminWorkspaceMiddleware implements MiddlewareInterface
{
    /** @var WorkspaceBuilder */
    private $workspaceBuilder;

    /** @var UpdateBadgeBuilder */
    private $badgeBuilder;

    /**
     * @param WorkspaceBuilder $workspaceBuilder
     * @param UpdateBadgeBuilder $badgeBuilder
     */
    public function __construct(WorkspaceBuilder $workspaceBuilder, UpdateBadgeBuilder $badgeBuilder)
    {
        $this->workspaceBuilder = $workspaceBuilder;
        $this->badgeBuilder = $badgeBuilder;
    }

    /**
     * @param callable $next
     * @return mixed
     */
    public function handle($next)
    {
        $admin = (array) auth('admin')->user();
        if (empty($admin)) {
            return $next();
        }

        $engine = app(DouView::class);
        if ($engine === null) {
            return $next();
        }

        $request = request();
        $engine->assign('global_admin', $admin);
        $engine->assign('workspace', $this->workspaceBuilder->build(
            (string) $request->routeModule(),
            (string) $request->route('category_id', '')
        ));

        $updateBadge = $this->badgeBuilder->build();
        if ($updateBadge !== null) {
            $engine->assign('unum', $updateBadge);
        }

        return $next();
    }
}
