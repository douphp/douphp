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
use Dou\Install\Service\ConfigWriterService;
use Dou\Install\Service\DatabaseInstallService;
use Dou\Install\Support\Helper;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 执行安装（POST）。
 *
 *   ?route=install/post     — 表单完整提交（成功后写入 session 用户名并 302 到 ?route=finish）
 *   ?route=install/callback — AJAX 预校验，回显错误片段；通过时返回空串让前端继续 submit
 */
class InstallController
{
    /** @var InstallContext */
    private $ctx;

    /** @var DatabaseInstallService */
    private $service;

    public function __construct(InstallContext $ctx)
    {
        $this->ctx = $ctx;
        $this->service = new DatabaseInstallService(
            $ctx->lang,
            new ConfigWriterService()
        );
    }

    /**
     * 表单完整提交。
     *
     * @return void
     */
    public function post()
    {
        require_once INSTALL_PATH . 'init/php_version_gate.php';
        douphp_require_min_php('5.6.0');

        $token = isset($_POST['token']) ? (string) $_POST['token'] : '';
        if (!$this->ctx->csrf->verify($token, 'static_admin')) {
            Helper::douMsg($this->ctx->view, $this->ctx->lang, $this->ctx->lang['cue_illegal'], 'index.php?route=setting');
        }

        $cue = $this->validate($_POST);
        if ($cue !== '') {
            Helper::douMsg($this->ctx->view, $this->ctx->lang, $cue, 'index.php?route=setting');
        }

        $result = $this->service->runInstall($_POST);
        $_SESSION['username'] = isset($result['username']) ? $result['username'] : '';
        Helper::redirect('index.php?route=finish');
    }

    /**
     * AJAX 预校验：错误时回显 cue 片段；通过时输出空让前端真正提交。
     *
     * @return void
     */
    public function callback()
    {
        require_once INSTALL_PATH . 'init/php_version_gate.php';
        douphp_require_min_php('5.6.0');

        $token = isset($_POST['token']) ? (string) $_POST['token'] : '';
        if (!$this->ctx->csrf->verify($token, 'static_admin')) {
            echo '<p class="cue"><strong>' . $this->ctx->lang['wrong'] . '：</strong>' . $this->ctx->lang['cue_illegal'] . '</p>';
            exit;
        }

        $cue = $this->validate($_POST);
        if ($cue !== '') {
            echo '<p class="cue"><strong>' . $this->ctx->lang['wrong'] . '：</strong>' . $cue . '</p>';
        }
        exit;
    }

    /**
     * 账号校验 + 数据库连接/建库校验。
     *
     * @param array $post
     * @return string 空串表示通过；否则返回错误提示
     */
    private function validate(array $post)
    {
        $cue = $this->service->validateAccount($post);
        if ($cue !== '') {
            return $cue;
        }

        $dbhost = isset($post['dbhost']) ? trim((string) $post['dbhost']) : '';
        $dbname = isset($post['dbname']) ? trim((string) $post['dbname']) : '';
        $dbuser = isset($post['dbuser']) ? trim((string) $post['dbuser']) : '';
        $dbpass = isset($post['dbpass']) ? (string) $post['dbpass'] : '';
        return $this->service->connectAndCreateDatabase($dbhost, $dbuser, $dbpass, $dbname);
    }
}
