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

namespace Dou\Admin\Service\Authorization;

use Dou\Admin\Service\Menu\AdminMenuService;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台模块访问授权判定。
 *
 * 承载「当前登录管理员能否访问指定后台模块 / 动作」的判定逻辑。
 * 调用面：{@see \Dou\Admin\Middleware\PermissionMiddleware} 在身份注入完成后调用。
 */
class AdminGate extends BaseService
{
    /** @var AdminMenuService */
    private $menuService;

    /**
     * @param AdminMenuService $menuService 可授项清单来源（判定标识是否为独立可授权项）
     */
    public function __construct(AdminMenuService $menuService)
    {
        $this->menuService = $menuService;
    }

    /**
     * 判断指定管理员是否可访问指定后台模块。
     *
     * 超级管理员（type 非 defined）放行；defined 类型按 action_list 白名单判定，规则三段式：
     *   1. 精确命中白名单 → 放行；
     *   2. 当前标识本身是独立可授项（{@see AdminMenuService::grantableNames()}）但未勾选 → 拒绝，
     *      不继承父模块（保证栏目模块 `m_category` 与 `m` 分开授权的语义）；
     *   3. 当前标识被某个组合权限键隐式覆盖（{@see AdminMenuService::implicitGrants()}）且该键
     *      已勾选 → 放行（如勾选 `site_home` 连带放行 `show` / `data`）；
     *   4. 非可授项子资源 → 回退声明给出的父模块（$parentModule）校验。
     * manager 模块的「编辑 / 更新自身资料」单独放行。
     *
     * 目标管理员 ID 与父模块由调用方（PermissionMiddleware）从 Request 取好显式传入；
     * 本类内部不读 Request。
     *
     * @param array $admin 管理员上下文（含 type / action_list / admin_id）
     * @param string $cur 当前路由 module（如 book_blacklist / product_category）
     * @param string $action 当前路由 action
     * @param int $targetId 当前请求 URL 中携带的目标管理员 ID（manager 自编辑判定使用），无则传 0
     * @param string $parentModule 声明给出的父模块（子资源继承用），无则传空串
     * @return bool
     */
    public function canAccess(array $admin, $cur, $action, $targetId = 0, $parentModule = '')
    {
        if (!isset($admin['type']) || $admin['type'] !== 'defined') {
            return true;
        }

        if (!$cur) {
            return false;
        }

        if ($this->isManagerSelfEdit($admin, $cur, $action, (int) $targetId)) {
            return true;
        }

        $actionList = isset($admin['action_list']) && $admin['action_list'] !== ''
            ? explode(',', $admin['action_list'])
            : array();

        if (in_array($cur, $actionList)) {
            return true;
        }

        if ($this->isGrantable($cur)) {
            return false;
        }

        $implicit = $this->menuService->implicitGrants();
        if (isset($implicit[$cur]) && in_array($implicit[$cur], $actionList)) {
            return true;
        }

        if ($parentModule !== '' && $parentModule !== $cur && in_array($parentModule, $actionList)) {
            return true;
        }

        return false;
    }

    /**
     * 判定某标识是否为权限池中的独立可授项（分类 / 内容分开授权的语义载体）。
     *
     * @param string $name
     * @return bool
     */
    private function isGrantable($name)
    {
        return in_array((string) $name, $this->menuService->grantableNames(), true);
    }

    /**
     * manager 模块编辑自身资料放行（仅本人 id）。
     *
     * @param array $admin
     * @param string $cur
     * @param string $action
     * @param int $targetId 调用方传入的目标管理员 ID
     * @return bool
     */
    public function isManagerSelfEdit(array $admin, $cur, $action, $targetId = 0)
    {
        if ($cur !== 'manager') {
            return false;
        }
        if ($action !== 'edit' && $action !== 'update') {
            return false;
        }

        $currentAdminId = isset($admin['admin_id']) ? (int) $admin['admin_id'] : 0;
        if ($currentAdminId < 1) {
            return false;
        }

        $targetAdminId = (int) $targetId;
        if ($targetAdminId < 1) {
            return false;
        }

        return $targetAdminId === $currentAdminId;
    }
}
