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

namespace Dou\Core\Service\Admin;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台审计日志 `action` 列常量集合（dou_admin_log.action）。
 *
 * 与 {@see \Dou\Core\Service\Audit\AuditService::writeAdminLog} 的 `$action` 入参一一对应；
 * 后台日志列表 {@see \Dou\Admin\Service\Manager\ManagerService::buildManagerLogData} 与
 * 首页最近日志 {@see \Dou\Admin\Service\Index\IndexService::getAdminLog} 通过
 * {@see label()} 统一翻译显示，确保两处一致。
 *
 * 常量值由历史落库格式保留方括号包裹（`[LOGIN_SUCCESS]` / `[CREATE]` 等），
 * 与 {@see \Dou\Core\Service\User\UserLogAction} 风格一致；
 * 新增 action 仅在末尾追加常量并同步加入 {@see all()}。
 *
 * 设计取舍：粗粒度英文常量（CREATE / UPDATE / DELETE 等 ~14 个），
 * 模块信息由 `dou_admin_log.module` 列单独承载，不在 action 里拆 PRODUCT_CREATE / ARTICLE_CREATE。
 */
class AdminLogAction
{
    /** 管理员登录成功 */
    const LOGIN_SUCCESS = '[LOGIN_SUCCESS]';

    /** 管理员登录失败（任意失败原因，细分见 AdminLogDetail） */
    const LOGIN_FAIL = '[LOGIN_FAIL]';

    /** 新增 / 添加（manager_create / nav_create / product_create 等统一收敛） */
    const CREATE = '[CREATE]';

    /** 编辑 / 修改 / 设置（manager_edit / nav_edit / xxx_set 等统一收敛） */
    const UPDATE = '[UPDATE]';

    /** 删除（manager_delete / nav_delete 等统一收敛） */
    const DELETE = '[DELETE]';

    /** 评论 / 留言回复（comment_reply / guestbook_reply） */
    const REPLY = '[REPLY]';

    /** 多步业务进入下一步（预约 confirm / checkin / complete 等） */
    const CONFIRM = '[CONFIRM]';

    /** 多步业务拒绝 / 退回 */
    const REJECT = '[REJECT]';

    /** 取消（订单取消等） */
    const CANCEL = '[CANCEL]';

    /** 启用（插件 / 模块开启） */
    const ENABLE = '[ENABLE]';

    /** 禁用（插件 / 模块关闭） */
    const DISABLE = '[DISABLE]';

    /** 模块 / 插件安装 */
    const INSTALL = '[INSTALL]';

    /** 模块 / 插件卸载 */
    const UNINSTALL = '[UNINSTALL]';

    /** 系统 / 模块升级 */
    const UPGRADE = '[UPGRADE]';

    /** 数据备份 */
    const BACKUP = '[BACKUP]';

    /** 数据还原 */
    const RESTORE = '[RESTORE]';

    /** 清缓存 */
    const CACHE_CLEAR = '[CACHE_CLEAR]';

    /** 业务联动重放 / 补发（如订单付款后联动积分 / 分销 / 会员升级的手动补发） */
    const REPLAY = '[REPLAY]';

    /**
     * 所有 action 候选（按定义顺序），供后台筛选下拉 / 白名单校验使用。
     *
     * @return array<int, string>
     */
    public static function all()
    {
        return array(
            self::LOGIN_SUCCESS,
            self::LOGIN_FAIL,
            self::CREATE,
            self::UPDATE,
            self::DELETE,
            self::REPLY,
            self::CONFIRM,
            self::REJECT,
            self::CANCEL,
            self::ENABLE,
            self::DISABLE,
            self::INSTALL,
            self::UNINSTALL,
            self::UPGRADE,
            self::BACKUP,
            self::RESTORE,
            self::CACHE_CLEAR,
            self::REPLAY,
        );
    }

    /**
     * 把字典常量翻译成 UI 可读文本；未命中字典的 action 字符串原样返回作 fallback。
     *
     * 供 ManagerService::buildManagerLogData / IndexService::getAdminLog 共用，
     * 避免两处显示不一致（[CREATE] vs 创建）。
     *
     * @param mixed $action 字典常量或历史 action 字符串
     * @return string UI 可读文本
     */
    public static function label($action)
    {
        $action = (string) $action;
        if (!in_array($action, self::all(), true)) {
            return $action;
        }
        $key = 'admin_log_action_' . strtolower(trim($action, '[]'));
        $text = lang($key);
        return $text !== '' ? $text : $action;
    }
}
