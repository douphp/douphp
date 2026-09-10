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
    /**
     * 子资源 module → 父 module 的鉴权别名表。
     *
     * 子资源仅做资源建模拆分，鉴权透明继承父模块（{@see ManagerService::adminActionList()}
     * 派生的 action_list 池子只含父模块名）。新增子资源时须同步登记，否则 defined 类型
     * 管理员勾选了父模块也会被 403 拦在子资源外。
     *
     * @var array<string,string>
     */
    private static $subModuleAliases = array(
        'chat_knowledge_category' => 'chat_knowledge',
        'ai_generate_batch' => 'ai',
        'ai_generate_form' => 'ai',
        'ai_generate_field' => 'ai',
        'ai_generate_translate' => 'ai',
        'ai_key' => 'ai',
        'ai_log' => 'ai',
        'ai_model' => 'ai',
        'ai_task' => 'ai',
        'weixin_media_article' => 'weixin_media',
    );

    /**
     * 判断指定管理员是否可访问指定后台模块。
     *
     * 超级管理员（type 非 defined）放行；defined 类型按 action_list 白名单判定（子资源
     * module 名经 {@see self::$subModuleAliases} 归一到父模块再查）；manager 模块的
     * 「编辑 / 更新自身资料」单独放行。
     *
     * 目标管理员 ID 由调用方（PermissionMiddleware）从 Request 取好显式传入；
     * 本类内部不读 Request。
     *
     * @param array $admin 管理员上下文（含 type / action_list / admin_id）
     * @param string $cur 当前路由 module
     * @param string $action 当前路由 action
     * @param int $targetId 当前请求 URL 中携带的目标管理员 ID（manager 自编辑判定使用），无则传 0
     * @return bool
     */
    public function canAccess(array $admin, $cur, $action, $targetId = 0)
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

        if (isset(self::$subModuleAliases[$cur])) {
            $cur = self::$subModuleAliases[$cur];
        }

        $actionList = isset($admin['action_list']) && $admin['action_list'] !== ''
            ? explode(',', $admin['action_list'])
            : array();
        return in_array($cur, $actionList);
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
