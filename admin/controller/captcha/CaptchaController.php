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

namespace Dou\Admin\Controller\Captcha;

use Dou\Admin\Controller\BaseController;
use Dou\Core\Infra\Security\Captcha;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台验证码控制器
 *
 * 与 {@see \Dou\Front\Controller\Captcha\CaptchaController} 各自挂在本端，
 * Session 命名空间分离（admin: `$_SESSION['admin_...']`、front: `$_SESSION['dou_...']`），
 * 后台登录页的验证码必须由本端入口下发，校验侧 {@see Captcha::verify()} 才能在
 * admin 命名空间下读到 HMAC。
 */
class CaptchaController extends BaseController
{
    /** @var Captcha */
    private $captcha;

    /**
     * @param Captcha $captcha
     */
    public function __construct(Captcha $captcha)
    {
        $this->captcha = $captcha;
    }

    /**
     * 下发图形验证码 PNG（`route=captcha`）。
     *
     * @return void
     */
    public function index()
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $this->captcha->createCaptcha(80, 30);
    }
}
