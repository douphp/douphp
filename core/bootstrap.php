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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

// PHP 版本检测
if (version_compare(PHP_VERSION, '5.6.0', '<')) {
    die('PHP版本过低，请升级到 PHP 5.6.0 或更高版本。');
}

// 定义应用根路径（bootstrap.php 位于 core/ 目录下，上一级为网站根）
define('ROOT_PATH', str_replace('\\', '/', dirname(dirname(__FILE__))) . '/');

// 提交配置目录（config/）与运行时存储目录（storage/）
define('CONFIG_PATH', ROOT_PATH . 'config/');
define('STORAGE_PATH', ROOT_PATH . 'storage/');

// 判断传输协议
define('HTTP', (
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ||
    (!empty($_SERVER['HTTP_FROM_HTTPS']) && strtolower($_SERVER['HTTP_FROM_HTTPS']) === 'on') ||
    (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ||
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
    (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off') ||
    (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https')
) ? 'https://' : 'http://');
define('IS_HTTPS', HTTP === 'https://');

// 未安装时跳转安装程序（须在载入 config/config.php 之前）
if (!file_exists(STORAGE_PATH . 'install.lock')) {
    $script = str_replace('\\', '/', isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '');
    if ($script === '' && isset($_SERVER['PHP_SELF'])) {
        $script = str_replace('\\', '/', $_SERVER['PHP_SELF']);
    }
    $onInstall = $script !== '' && preg_match('#/install(/|$)#', $script);
    if (!$onInstall && isset($_SERVER['REQUEST_URI'])) {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $onInstall = is_string($path) && preg_match('#/install(/|$)#', $path);
    }
    if (!$onInstall) {
        header('Location: ' . (preg_match('#/(admin|api)(/|$)#', $script) ? '../install/index.php' : 'install/index.php'));
        exit();
    }
}

// 载入站点配置文件（定义 $dbhost/$dbuser 等数据库变量及 DOU_CHARSET、ADMIN_DIR 等常量）
// 先加载 storage/state/admin_dir.php 文件（如果存在），用于定义 $admining 等变量
$adminingFile = STORAGE_PATH . 'state/admin_dir.php';
if (file_exists($adminingFile)) {
    require_once($adminingFile);
}
require_once(CONFIG_PATH . 'config.php');

// 定义DouPHP基础常量
define('CORE_PATH', ROOT_PATH . 'core/');
define('LIBRARY_PATH', CORE_PATH . 'library/');
define('FRONT_PATH', ROOT_PATH . 'front/');
define('API_PATH', ROOT_PATH . API_DIR . '/');
define('ADMIN_PATH', ROOT_PATH . ADMIN_DIR . '/');
define('MINIPROGRAM_PATH', ROOT_PATH . MINIPROGRAM_DIR . '/');
define('PLUGIN_PATH', ROOT_PATH . 'plugin/');

// 手机站目录名（业务代码中被大量引用的全局常量）
if (!defined('M_DIR')) {
    define('M_DIR', 'm');
}

// 统一读取一次 config/module.php（供 autoload / Common / Router 复用）
if (!defined('DOU_MODULE_SETTING')) {
    $moduleSetting = array();
    $moduleFile = CONFIG_PATH . 'module.php';
    if (file_exists($moduleFile)) {
        $loaded = include $moduleFile;
        if (is_array($loaded)) {
            $moduleSetting = $loaded;
        }
    }
    define('DOU_MODULE_SETTING', serialize($moduleSetting));
}

if (!defined('DOU_MODULE_MAP')) {
    $douModuleMap = array(
        'column' => array(),
        'single' => array(),
        'all' => array(),
    );
    $module = unserialize(DOU_MODULE_SETTING);
    if (is_array($module)) {
        $douModuleMap['column_module'] = isset($module['column_module']) ? array_filter($module['column_module']) : array();
        $douModuleMap['single_module'] = isset($module['single_module']) ? array_filter($module['single_module']) : array();
        $douModuleMap['all_module'] = array_merge($douModuleMap['column_module'], $douModuleMap['single_module']);
    }
    define('DOU_MODULE_MAP', serialize($douModuleMap));
}

// 统一收敛 DB 配置，供 InitTrait::instantiateCoreObjects 实例化 Connection 时使用
if (!defined('DOU_DB_CONFIG')) {
    define('DOU_DB_CONFIG', serialize(array(
        'host' => isset($dbhost) ? $dbhost : '',
        'user' => isset($dbuser) ? $dbuser : '',
        'pass' => isset($dbpass) ? $dbpass : '',
        'name' => isset($dbname) ? $dbname : '',
        'prefix' => isset($prefix) ? $prefix : '',
    )));
}

// 注册自动加载（ClassMap + PSR-4）
require_once(CORE_PATH . 'autoload.php');

// 注册根命名空间短别名（AliasLoader 内部走惰性 class_alias）
// 已注册的短名清单：见 map 本身；插件 / 模块如需补充自身短名，可在自身引导阶段
// 再次 AliasLoader::getInstance(array(...))->register() 追加合并。
\Dou\Core\Foundation\Facade\AliasLoader::getInstance(array(
    'DB' => \Dou\Core\Facade\DB::class,
    'Session' => \Dou\Core\Facade\Session::class,
    'Storage' => \Dou\Core\Filesystem\Storage::class,
    'Request' => \Dou\Core\Facade\Request::class,
    'Route' => \Dou\Core\Facade\Route::class,
    'Url' => \Dou\Core\Facade\Url::class,
    'Check' => \Dou\Core\Support\Check::class,
    'Csrf' => \Dou\Core\Facade\Csrf::class,
    'Xss' => \Dou\Core\Facade\Xss::class,
    'Zip' => \Dou\Core\Facade\Zip::class,
    'Image' => \Dou\Core\Facade\Image::class,
    'Attachment' => \Dou\Core\Facade\Attachment::class,
    'Audit' => \Dou\Core\Facade\Audit::class,
    'Message' => \Dou\Core\Facade\Message::class,
    'View' => \Dou\Core\Facade\View::class,
    'Arr' => \Dou\Core\Support\Arr::class,
    'Num' => \Dou\Core\Support\Num::class,
    'Str' => \Dou\Core\Support\Str::class,
    'Util' => \Dou\Core\Support\Util::class,
    'Module' => \Dou\Core\Foundation\Extension\Module::class,
))->register();

// 初始化 DI 容器（单例，供路由调度器与 app() 助手使用）
// 使用方式：
//   Dou\Core\Foundation\Container\Container::getInstance()->make($className)
//   app($className) // 仅在视图/路由闭包等不便构造注入的场景使用
\Dou\Core\Foundation\Container\Container::getInstance();

// 入站路由调度器（Dou\Core\Facade\Route 门面底层实例）须在 Init::boot 之前可用：
// 三端 index.php 在 Init::boot 之前要先 `Route::setDelegate(...)` / `Route::current()`，
// 因此该绑定必须放在 bootstrap 阶段，不能放进 InitTrait::instantiateCoreObjects。
// DelegatingRouter 构造函数无依赖，可在此处早绑；UrlGenerator / RouteIdValidator 仍由 InitTrait 注册（依赖 DB::）。
\Dou\Core\Foundation\Container\Container::getInstance()->instance(
    \Dou\Core\Web\Routing\DelegatingRouter::class,
    new \Dou\Core\Web\Routing\DelegatingRouter()
);

// Request 单例须在 Init::boot 之前可用：前台 index.php 在 boot 之前要 setRouteLangSign / setRouteString
// （LangPrefixParser 剥语言前缀），且 Route::current() 的 lang/route/is_home 元信息从 Request 读取。
// Request::capture() 仅读超全局、无 DB 依赖，可在此早绑；InitTrait 后续复用同一实例。
\Dou\Core\Foundation\Container\Container::getInstance()->instance(
    \Dou\Core\Web\Http\Request::class,
    \Dou\Core\Web\Http\Request::capture()
);

// 加载 app() 全局助手（容器语法糖）
require_once(CORE_PATH . 'foundation/container/helpers.php');

// 加载 HTTP 响应全局助手（view / json / redirect / response）
require_once(CORE_PATH . 'web/http/helpers.php');

// 可选邮件类事件监听（依赖 param.* 开关，默认不发送额外邮件）
\Dou\Core\Foundation\Event\MailNotificationRegistrar::register();

// 场景分发器注册器登记（实际 register() 延迟到首次 dispatch、Config 已就绪后执行）
\Dou\Core\Foundation\Event\Scene\SceneRegistry::addBootstrapper(
    \Dou\Core\Foundation\Event\Scene\Registrars\OrderPaidSceneRegistrar::class
);
