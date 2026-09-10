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
use Dou\Install\Service\EnvironmentCheckService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 环境检测：列出系统信息与目录可写性，并按 Web 服务器复制对应伪静态规则。
 */
class CheckController
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
        $service = new EnvironmentCheckService($this->ctx->lang);

        $sys_info = $service->collectSystemInfo();
        $info = $service->collectWriteableInfo();
        $service->copyRewriteRule($sys_info['web_server']);

        $php_ok = !empty($sys_info['php_ok']);
        $block_next = !empty($info['no_write']) || !$php_ok;

        $title = $this->ctx->lang['douphp'] . ' &rsaquo; ' . $this->ctx->lang['check'];

        $this->ctx->view->assign('title', $title);
        $this->ctx->view->assign('sys_info', $sys_info);
        $this->ctx->view->assign('writeable', $info['writeable']);
        $this->ctx->view->assign('no_write', $info['no_write']);
        $this->ctx->view->assign('php_ok', $php_ok);
        $this->ctx->view->assign('block_next', $block_next);
        $this->ctx->view->display('check.htm');
    }
}
