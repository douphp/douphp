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

namespace Dou\Front\Middleware;

use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Middleware\AbstractCsrfMiddleware;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台 CSRF 校验中间件
 *
 * 前台令牌模型：登录会员共享静态令牌 static_user（POST 表单与带 token 的 GET 链接复用）；
 * 匿名表单（登录 / 注册 / 找回密码 / 留言 / 落地页 / 分销申请 / 咨询）使用一次性令牌抗重放。
 *
 * 校验由本中间件自动完成，控制器只保留 `csrf()->generate()/token()` 用于渲染表单令牌。
 *
 * - {@see tokenIdFor()}：9 类一次性令牌路由按表映射，其余落 static_user；
 * - 外部支付回调（plugin/notify、plugin/finish，无 session 令牌）由路由级
 *   `->withoutMiddleware(['csrf'])` 声明式豁免，不在本中间件内硬编码名单；
 * - {@see getTokenRoutes()}：取消预约 / 商家处理 / 余额扣款等带 token 的 GET 链接，GET 也校验。
 */
class CsrfMiddleware extends AbstractCsrfMiddleware
{
    /**
     * 一次性令牌路由 → 令牌 id 映射。
     *
     * @var array
     */
    private static $oneTimeTokenRoutes = array(
        'user/register_post' => 'user_register',
        'user/login_post' => 'user_login',
        'user/login_phone_post' => 'user_login_phone',
        'user/password_reset_post' => 'user_password_reset',
        'landing/submit' => 'landing_submit',
        'guestbook/store' => 'guestbook',
        'distribution/apply_post' => 'distribution_apply',
        'consultation/store' => 'consultation',
    );

    /**
     * @param string $module
     * @param string $action
     * @param string $sub
     * @param array $candidates
     * @return string
     */
    protected function tokenIdFor($module, $action, $sub, array $candidates)
    {
        foreach ($candidates as $candidate) {
            if (isset(self::$oneTimeTokenRoutes[$candidate])) {
                return self::$oneTimeTokenRoutes[$candidate];
            }
        }

        return 'static_user';
    }

    /**
     * @return array
     */
    protected function getTokenRoutes()
    {
        return array(
            'book/work', 'book/cancel', 'money/use',
            'order/cart/destroy', 'order/user/cancel',
            'user/logout',
        );
    }

    /**
     * 前台 CSRF 校验失败：正常会话过期（页面停留过久、重新登录令牌旋转等）占绝大多数，
     * 优先给出可操作指引（刷新页面 / 重新登录），语言包缺 csrf_page_expired 键时回退 illegal。
     * 提示后跳首页。
     *
     * @return void
     */
    protected function reject()
    {
        throw new DomainException(lang('csrf_page_expired', lang('illegal')), HOME_URL);
    }
}
