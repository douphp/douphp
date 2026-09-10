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

namespace Dou\Install\Controller;

use Dou\Install\Foundation\Context\InstallContext;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 配置系统：渲染数据库 + 管理员表单。提交目标 route=install/post。
 */
class SettingController
{
    /** @var InstallContext */
    private $ctx;

    public function __construct(InstallContext $ctx)
    {
        $this->ctx = $ctx;
    }

    /**
     * @return void
     */
    public function index()
    {
        require_once INSTALL_PATH . 'init/php_version_gate.php';
        douphp_require_min_php('5.6.0');

        $title = $this->ctx->lang['douphp'] . ' &rsaquo; ' . $this->ctx->lang['setting'];
        $token = $this->ctx->csrf->generate('static_admin');

        $this->ctx->view->assign('title', $title);
        $this->ctx->view->assign('token', $token);
        $this->ctx->view->display('setting.htm');
    }
}
