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

namespace Dou\Install\Init;

use Dou\Core\Foundation\Container\Container;
use Dou\Core\Foundation\Csrf\CsrfManager;
use Dou\Core\Infra\Session\Session;
use Dou\Core\Web\Template\DouView;
use Dou\Install\Foundation\Context\InstallContext;
use Dou\Install\Support\Helper;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * install 启动器。
 *
 * 安装阶段尚不存在 config/config.php、ADMIN_DIR 等常量，不能调用 core/bootstrap.php
 * 或 core/autoload.php。本启动器只装配安装界面所需的最小核心类：
 *   - DouView     模板（install/view，编译到 install/cache）
 *   - CsrfManager token 工具（仅用 generate/token/verify）
 *   - Session     容器登记（CsrfManager 通过 Session facade 读写 $_SESSION[DOU_ID]['token']）
 *   - lang        语言串数组
 *
 * 输入校验改为静态调用 {@see \Dou\Core\Support\Check}，不再注入实例。
 */
class Init
{
    /**
     * 启动 install，返回填充好的上下文。
     *
     * @return InstallContext
     */
    public function boot()
    {
        $this->startSession();
        $this->setErrorReporting();
        $this->setTimezone();
        $this->setHeader();

        $ctx = new InstallContext();
        $this->setupView($ctx);
        $this->loadLang($ctx);
        $this->setupSecurity($ctx);

        ob_start();

        return $ctx;
    }

    /**
     * 开启 SESSION（已开启时跳过）。
     *
     * @return void
     */
    private function startSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * 关闭 NOTICE/WARNING，仅显示 ERROR 级别。
     *
     * @return void
     */
    private function setErrorReporting()
    {
        error_reporting(E_ALL ^ (E_NOTICE | E_WARNING));
    }

    /**
     * 统一时区。
     *
     * @return void
     */
    private function setTimezone()
    {
        date_default_timezone_set('PRC');
    }

    /**
     * 基础响应头与禁用页面缓存。
     *
     * @return void
     */
    private function setHeader()
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Expires: Fri, 14 Mar 1980 20:53:00 GMT');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
    }

    /**
     * 装配 DouView：模板目录 install/view，编译目录 install/cache。
     *
     * @param InstallContext $ctx
     * @return void
     */
    private function setupView(InstallContext $ctx)
    {
        $ctx->view = new DouView();
        $ctx->view->template_dir = ROOT_PATH . 'install/view';
        $ctx->view->compile_dir = INSTALL_PATH . 'cache/';
        $ctx->view->leftDelimiter = '{';
        $ctx->view->rightDelimiter = '}';

        if (!file_exists($ctx->view->compile_dir)) {
            @mkdir($ctx->view->compile_dir, 0777, true);
        }
        if (!is_dir($ctx->view->compile_dir)) {
            Helper::plainExit('install/cache 不可写，请确认目录权限。');
        }

        $ctx->view->registerPrefilter(function ($source, $engine) {
            return preg_replace('/<!--.*{(.*)}.*-->/U', '{$1}', $source);
        });
    }

    /**
     * 加载语言串到 ctx->lang 与 DouView。
     *
     * @param InstallContext $ctx
     * @return void
     */
    private function loadLang(InstallContext $ctx)
    {
        $lang = include(ROOT_PATH . 'install/init/lang.php');
        $ctx->lang = is_array($lang) ? $lang : array();
        $ctx->view->assign('lang', $ctx->lang);
    }

    /**
     * 构造 CsrfManager；将 Session 注册到容器单例。
     *
     * CsrfManager::generate/token/verify 通过 {@see \Dou\Core\Facade\Session} 静态访问
     * Session，必须先把 Session 实例绑定到容器，否则会报 "Facade accessor … not bound"。
     * 这一步等同 InitTrait 中的 `Container::instance(Session::class, new Session())`。
     *
     * @param InstallContext $ctx
     * @return void
     */
    private function setupSecurity(InstallContext $ctx)
    {
        Container::getInstance()->instance(Session::class, new Session());
        $ctx->csrf = new CsrfManager();
    }
}
