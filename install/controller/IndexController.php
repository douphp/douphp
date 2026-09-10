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
 * 欢迎页：展示许可协议，确认后进入环境检测（?route=check）。
 */
class IndexController
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
        $title = $this->ctx->lang['welcome'];
        $this->ctx->view->assign('title', $title);
        $this->ctx->view->display('index.htm');
    }
}
