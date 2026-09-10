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

if (!defined('IN_DOUCO') || !defined('IS_INSTALL')) {
    die('Hacking attempt');
}

// 基础路径与协议（不复用 core/bootstrap.php：bootstrap 会触发 install 跳转、
// 并在 config/config.php 缺失时直接 fatal，与 install 作为「初次安装入口」的定位冲突）
define('ROOT_PATH', str_replace('\\', '/', dirname(dirname(__FILE__))) . '/');
define('INSTALL_PATH', ROOT_PATH . 'install/');
define('I_PATH', 'install');
define('HTTP', (
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ||
    (!empty($_SERVER['HTTP_FROM_HTTPS']) && strtolower($_SERVER['HTTP_FROM_HTTPS']) === 'on') ||
    (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ||
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
    (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off') ||
    (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https')
) ? 'https://' : 'http://');
define('IS_HTTPS', HTTP === 'https://');

define('ROOT_URL', preg_replace('/' . I_PATH . '\//Ums', '', dirname(HTTP . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']) . '/'));

// core 自动加载器依赖 ADMIN_DIR/API_DIR 等常量（在 config/config.php 落盘后才有），
// 因此安装阶段只注册一个最小的 Dou\Core\* 加载器即可。
spl_autoload_register(function ($className) {
    if (strncmp($className, 'Dou\\Core\\', 9) !== 0) {
        return;
    }
    $relative = strtr(substr($className, 9), '\\', '/');
    $parts = explode('/', $relative);
    $basename = array_pop($parts);
    $directory = strtolower(implode('/', $parts));
    $classFile = ROOT_PATH . 'core/' . ($directory === '' ? '' : $directory . '/') . $basename . '.php';
    if (file_exists($classFile)) {
        require_once($classFile);
    }
});

// 额外注册 Dou\Install\* 命名空间加载器
spl_autoload_register(function ($className) {
    if (strncmp($className, 'Dou\\Install\\', 12) !== 0) {
        return;
    }
    $namespaceSuffix = substr($className, 12);
    $parts = explode('\\', $namespaceSuffix);
    $classBasename = array_pop($parts);
    $dirParts = array_map('strtolower', $parts);
    $relative = ($dirParts ? implode('/', $dirParts) . '/' : '') . $classBasename . '.php';
    $classFile = ROOT_PATH . 'install/' . $relative;
    if (file_exists($classFile)) {
        require_once($classFile);
    }
});

// DOU_ID 用作 Session 命名空间（$_SESSION[DOU_ID]，见 core/infra/session/Session.php），
// CsrfManager 通过 Session facade 在 [DOU_ID]['token'][...] 下读写令牌。install 各端独立
// 起一个稳定 id，避免与已安装站点的 admin / front session 撞键
if (!defined('DOU_ID')) {
    define('DOU_ID', 'admin_install_' . substr(md5('install'), 0, 8));
}

use Dou\Install\Init\Init;
use Dou\Install\Foundation\Routing\Router;
use Dou\Install\Support\Helper;

try {
    $ctx = (new Init())->boot();
    $map = require(ROOT_PATH . 'install/init/route.php');
    (new Router($map))->dispatch($ctx);
} catch (\Exception $e) {
    if (isset($ctx) && $ctx && $ctx->view) {
        Helper::douMsg($ctx->view, $ctx->lang, 'Install Error: ' . $e->getMessage(), '', '', 30);
    }
    Helper::plainExit('Install Error: ' . $e->getMessage());
}
