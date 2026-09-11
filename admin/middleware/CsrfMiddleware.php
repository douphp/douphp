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

use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Middleware\AbstractCsrfMiddleware;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 CSRF 校验中间件
 *
 * 后台令牌模型统一：登录成功后下发共享静态令牌 static_admin，各表单页 `csrf()->token()` 渲染、
 * 提交时由本中间件自动校验，无需控制器再写 checkToken。
 *
 * 完全豁免 csrf 的路由（`login/post` 登录提交、`cloud/install_step` 安装 JSON API）由路由级
 * `->withoutMiddleware(['csrf'])` 声明式豁免，不在本中间件内硬编码名单。
 *
 * 例外令牌：
 * - `login/password_reset_post`（找回密码提交）：匿名流程，使用一次性令牌 password_reset；
 * - `backup/backup`、`backup/import`：分卷备份 / 导入为带 token 的 GET 续跑链接，GET 也校验；
 * - `order/report/export`：报表导出为带 token 的 GET 链接，GET 也校验。
 */
class CsrfMiddleware extends AbstractCsrfMiddleware
{
    /**
     * @param string $module
     * @param string $action
     * @param string $sub
     * @param array $candidates
     * @return string
     */
    protected function tokenIdFor($module, $action, $sub, array $candidates)
    {
        if (in_array('login/password_reset_post', $candidates, true)) {
            return 'password_reset';
        }

        return 'static_admin';
    }

    /**
     * @return array
     */
    protected function getTokenRoutes()
    {
        return array(
            'backup/backup',
            'backup/import',
            'order/report/export',
        );
    }

    /**
     * 后台 CSRF 校验失败：正常会话过期（页面停留过久、重新登录令牌旋转等）占绝大多数，
     * 优先给出可操作指引（刷新页面 / 重新登录），语言包缺 csrf_page_expired 键时回退 illegal。
     * 抛 DomainException，由 admin 入口 catch 后走 message()->respond() 输出
     * 后台 dou_msg.htm 统一提示页（含倒计时、返回按钮、admin 布局），与 front 端 reject 对称。
     *
     * @return void
     */
    protected function reject()
    {
        throw new DomainException(lang('csrf_page_expired', lang('illegal')), route('admin.index'));
    }
}
