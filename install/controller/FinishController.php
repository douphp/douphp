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
use Dou\Install\Service\FinishService;
use Dou\Install\Service\InstallLockService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 完成页：写入 config/module.php、生成 install.lock，渲染成功页。
 *
 * 写入安装锁后，Router 的下次请求都会强制走 LockController；此处先把页面渲染
 * 完成再返回，避免用户被锁页拦截。
 */
class FinishController
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
        $service = new FinishService(new InstallLockService());
        $service->finalize();

        $title = $this->ctx->lang['douphp'] . ' &rsaquo; ' . $this->ctx->lang['finish_title'];
        $username = isset($_SESSION['username']) ? (string) $_SESSION['username'] : '';

        $this->ctx->view->assign('title', $title);
        $this->ctx->view->assign('username', $username);
        $this->ctx->view->display('finish.htm');
    }
}
