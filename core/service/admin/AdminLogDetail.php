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
 * 后台审计日志 `details` 列离散标签字典（dou_admin_log.details）。
 *
 * 与 {@see \Dou\Core\Service\Audit\AuditService::writeAdminLog} 的 `$details` 入参对应；
 * **仅收敛离散枚举语义**（登录失败原因、ManagerService::update 老密码错等），
 * 业务 CRUD caller 的 details 仍按动态对象（username / sn / ID / 标题）直传，
 * 两类用法在同一列共存，与 {@see \Dou\Core\Service\User\UserLogDetail} 设计同源。
 *
 * 与 {@see AdminLogAction} 配合：`action` 列分类、`details` 列细分。
 */
class AdminLogDetail
{
    /** 图形验证码已过期（{@see \Dou\Core\Infra\Security\Captcha::verify()} 统一校验 TTL，过期与不匹配记 CAPTCHA_WRONG） */
    const CAPTCHA_EXPIRED = 'captcha_expired';

    /** 图形验证码不匹配（{@see \Dou\Admin\Service\Login\AdminLoginFlow::checkCaptcha}） */
    const CAPTCHA_WRONG = 'captcha_wrong';

    /** 用户名格式非法（{@see \Dou\Admin\Service\Login\AdminLoginFlow::handle} line 80-86） */
    const USERNAME_INVALID = 'username_invalid';

    /** 同 IP 登录失败次数已超阈值，命中 IP 限流（line 88-95） */
    const IP_RATE_LIMITED = 'ip_rate_limited';

    /** 账号已被锁定（连续失败次数过多，line 175-185） */
    const ACCOUNT_LOCKED = 'account_locked';

    /** 用户名 / 密码错误兜底（含用户不存在 / 密码不匹配，line 187-201） */
    const INPUT_WRONG = 'input_wrong';

    /** 编辑管理员时旧密码校验失败（{@see \Dou\Admin\Service\Manager\ManagerService::update} line 206） */
    const OLD_PASSWORD_WRONG = 'old_password_wrong';

    /**
     * 所有 detail 候选（按定义顺序），供后台筛选下拉 / 白名单校验使用。
     *
     * @return array<int, string>
     */
    public static function all()
    {
        return array(
            self::CAPTCHA_EXPIRED,
            self::CAPTCHA_WRONG,
            self::USERNAME_INVALID,
            self::IP_RATE_LIMITED,
            self::ACCOUNT_LOCKED,
            self::INPUT_WRONG,
            self::OLD_PASSWORD_WRONG,
        );
    }
}
