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

namespace Dou\Core\Init;

use Dou\Core\Contract\MessageResponderInterface;
use Dou\Core\Facade\DB;
use Dou\Core\Filesystem\FilesystemManager;
use Dou\Core\Foundation\Auth\AuthManager;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Container\Container;
use Dou\Core\Foundation\Csrf\CsrfManager;
use Dou\Core\Foundation\Exception\SiteDebugExceptionRenderer;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Foundation\Lang\LangBag;
use Dou\Core\Foundation\Locale\Locale;
use Dou\Core\Infra\Database\Connection;
use Dou\Core\Infra\Image\ImageManager;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Infra\Security\Xss;
use Dou\Core\Infra\Session\Session;
use Dou\Core\Orm\Model;
use Dou\Core\Service\Attachment\AttachmentService;
use Dou\Core\Service\Audit\AuditService;
use Dou\Core\Service\Noop\NullMessageResponder;
use Dou\Core\Support\Zip;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Routing\RouteIdValidator;
use Dou\Core\Web\Routing\UrlGenerator;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 三端 Init 共享的基础步骤。
 *
 * 采用 trait 而不是基类：保持三端 Init（Dou\{Front|Admin|Api}\Init）命名空间独立，
 * 仅抽象启动流程中真正重复的钩子步骤，差异化顺序仍由各端自行控制。
 *
 * 子类职责：
 *   1) 按自身顺序调用下列钩子方法（本文件内声明顺序为建议阅读顺序，与运行时调用顺序无关）
 *   2) 端特定的常量定义 / Smarty 初始化 / 模板变量填充等
 *
 * 钩子方法建议阅读顺序：
 *   setErrorReporting, setTimezone, loadCustomFile, loadFilesystemsConfig, loadAiConfig, loadSecurityConfig,
 *   computeRootUrl, startSession, instantiateCoreObjects, instantiateCommonFactories,
 *   defineShellConstants, loadLanguageFiles, initLogRuntime；
 *   handleGlobalException / handleShutdownFatal 由 initLogRuntime 注册，一般不在 Init 中直接调用。
 */
trait InitTrait
{
    /**
     * 各端是否在「全站 site.log_runtime 打开」时仍写本端日志（data/log/{front|admin|api}/）。
     * 开发者在本 Trait 内改为 false 即可关闭该端；全站总开关关时三端均不写。
     *
     * @var array
     */
    private static $logRuntimeForShell = array(
        'front' => true,
        'admin' => true,
        'api' => true,
    );

    /**
     * 当前请求待加载的语言文件清单（绝对路径列表）。
     *
     * 由各端 loadModules / loadCore 阶段从 {@see \Dou\Core\Bootstrap\SystemBootstrap::loadCore()}
     * 的 `lang` 分区暂存到此，供 {@see loadLanguageFiles()} 直接 require，不经 Config 中转。
     *
     * @var array
     */
    protected $languageManifest = array();

    // -----------------------------------------------------------------
    // 开发者可配置项与启动策略
    // -----------------------------------------------------------------

    /**
     * 设置错误报告级别
     *
     * @return void
     */
    protected function setErrorReporting()
    {
        // 全量上报：不再用 E_ALL ^ (E_NOTICE | E_WARNING) 静默屏蔽诊断级错误。
        // 非 debug 的 notice/warning/deprecated 由 initLogRuntime 末端注册的 handleRuntimeError
        // 去重限流后写入 data/log/，而非直接丢弃；debug 下不接管，交还 PHP / Whoops 直接可见。
        error_reporting(E_ALL);
    }

    /**
     * 设置时区
     *
     * @return void
     */
    protected function setTimezone()
    {
        date_default_timezone_set('PRC');
    }

    /**
     * 读取 storage/state/admin_dir.php 自定义扩展（front/api 共用）
     *
     * @return void
     */
    protected function loadCustomFile()
    {
        if (file_exists($f = STORAGE_PATH . 'state/admin_dir.php')) {
            include_once($f);
        }
    }

    /**
     * 加载 config/file.php 至 Config
     *
     * @return void
     */
    protected function loadFilesystemsConfig()
    {
        $path = CONFIG_PATH . 'file.php';
        if (file_exists($path)) {
            $a = include $path;
            if (is_array($a) && isset($a['filesystems'])) {
                Config::set('filesystems', $a['filesystems']);
            }
        }
    }

    /**
     * 加载 config/ai.php 至 Config
     *
     * @return void
     */
    protected function loadAiConfig()
    {
        $path = CONFIG_PATH . 'ai.php';
        if (file_exists($path)) {
            $a = include $path;
            if (is_array($a) && isset($a['ai'])) {
                Config::set('ai', $a['ai']);
            }
        }
    }

    /**
     * 加载 config/security.php 至 Config，并据此设置 Request 可信代理名单。
     *
     * 必须在任何 Request::ip() 调用（最早一处是 instantiateCommonFactories 构造
     * AuditService）之前调用：否则伪造的 X-Forwarded-For 会在中间件管道运行前就被采信。
     * 中间件层 TrustProxyMiddleware 会幂等地再断言一次同名设置。
     *
     * @return void
     */
    protected function loadSecurityConfig()
    {
        $path = CONFIG_PATH . 'security.php';
        if (file_exists($path)) {
            $a = include $path;
            if (is_array($a) && isset($a['security'])) {
                Config::set('security', $a['security']);
            }
        }
        $proxies = Config::get('security.trusted_proxies', array());
        Request::setTrustedProxies(is_array($proxies) ? $proxies : array());
    }

    // -----------------------------------------------------------------
    // 请求环境与会话
    // -----------------------------------------------------------------

    /**
     * 根据当前请求的 HTTP_HOST 与 PHP_SELF 所在目录推导 baseUrl 并直接写入 Request 单例。
     *
     * 该值仅供 SiteConfigAssembler 在 DB.config.domain 为空时作 fallback 使用；
     * 业务侧应使用 Config::get('site.root_url') 或常量 ROOT_URL 取站点对外规范根 URL。
     *
     * @return string 当前推导出的根 URL（同时写入 Request::baseUrl）
     */
    protected function computeRootUrl()
    {
        $dirname = dirname(HTTP . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']) . '/';
        $container = Container::getInstance();
        if ($container->has(Request::class)) {
            $container->make(Request::class)->setBaseUrl($dirname);
        }
        return $dirname;
    }

    /**
     * 启动会话（先硬化会话 Cookie，再 session_start）。
     *
     * 本方法早于 loadSecurityConfig() 执行（三端 boot 顺序如此），故直接读
     * config/security.php 的 session 块而不经 Config 门面；文件缺席时按内置
     * 安全默认值（httponly + SameSite=Lax + use_strict_mode + 跟随 IS_HTTPS）。
     *
     * @return void
     */
    protected function startSession()
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $config = $this->loadSessionCookieConfig();

        $httponly = isset($config['httponly']) ? (bool) $config['httponly'] : true;
        $samesite = isset($config['samesite']) && is_string($config['samesite']) && $config['samesite'] !== ''
            ? $config['samesite']
            : 'Lax';
        $secure = isset($config['secure']) && $config['secure'] !== null
            ? (bool) $config['secure']
            : (defined('IS_HTTPS') && IS_HTTPS);
        $strictMode = isset($config['use_strict_mode']) ? (bool) $config['use_strict_mode'] : true;

        if ($strictMode) {
            @ini_set('session.use_strict_mode', '1');
        }
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', $httponly ? '1' : '0');

        $params = session_get_cookie_params();
        if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
            session_set_cookie_params(array(
                'lifetime' => $params['lifetime'],
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => $httponly,
                'samesite' => $samesite,
            ));
        } else {
            // PHP 7.3 之前 session_set_cookie_params 无 samesite 形参：借 path 注入（同 UserAuthService::setCookie）。
            session_set_cookie_params(
                $params['lifetime'],
                '/; samesite=' . strtolower($samesite),
                '',
                $secure,
                $httponly
            );
        }

        session_start();
    }

    /**
     * 读取 config/security.php 中的 session Cookie 配置块。
     *
     * @return array
     */
    private function loadSessionCookieConfig()
    {
        $path = CONFIG_PATH . 'security.php';
        if (!file_exists($path)) {
            return array();
        }

        $a = include $path;
        if (is_array($a) && isset($a['security']['session']) && is_array($a['security']['session'])) {
            return $a['security']['session'];
        }

        return array();
    }

    // -----------------------------------------------------------------
    // 核心对象、容器与站点上下文
    // -----------------------------------------------------------------

    /**
     * 实例化通用核心对象，直接挂入容器单例。
     *
     * 包含：db / request / session / csrf / xss / route。
     * dou / message 等端侧门面在各端 Init 内构造并经容器单例化。
     *
     * @return void
     */
    protected function instantiateCoreObjects()
    {
        $container = Container::getInstance();

        // PHP 8.1+ 起 mysqli 默认 MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT，会把连接/查询
        // 失败抛成 mysqli_sql_exception；本仓库 Connection 依赖「返回 false + 自有 error() 处理」的
        // 旧式错误模型。显式关闭报告，使 5.6–8.x 全版本行为一致（错误由 Connection 自行判定）。
        if (function_exists('mysqli_report')) {
            mysqli_report(MYSQLI_REPORT_OFF);
        }

        $db = defined('DOU_DB_CONFIG') ? unserialize(DOU_DB_CONFIG) : array();
        $container->instance(Connection::class, new Connection(
            $db['host'], $db['user'], $db['pass'], $db['name'], $db['prefix'], DOU_CHARSET
        ));

        // Request 已在 bootstrap 早绑（前台入口需在 boot 前写入语言前缀 / 路由字符串）；
        // 此处复用同一实例，避免二次 capture 丢失入口已写入的路由元信息。
        if (!$container->has(Request::class)) {
            $container->instance(Request::class, Request::capture());
        }
        $container->instance(Session::class, new Session());
        $container->instance(CsrfManager::class, new CsrfManager());
        $container->instance(Xss::class, new Xss());
        $container->instance(UrlGenerator::class, new UrlGenerator());
        $container->instance(RouteIdValidator::class, new RouteIdValidator());

        // 译串表载体：早绑空 Bag，确保 loadLanguageFiles 之前的 lang() 调用也能解析；
        // 后续 loadLanguageFiles 经 replace() 整表填充。
        $container->instance(LangBag::class, new LangBag());

        // 当前请求语言选择载体：早绑空 Locale，Init.resolveCurLang 解析 URL 后调用 set() 写入。
        if (!$container->has(Locale::class)) {
            $container->instance(Locale::class, new Locale());
        }

        // AuthManager 单例：业务侧通过 `auth('admin'|'front'|'api')` 显式解析对应端 guard，
        // 工厂由各端 registerAuthGuard 写入；没有「默认 guard」概念，shorthand `auth()` 不可用。
        if (!$container->has(AuthManager::class)) {
            $container->instance(AuthManager::class, new AuthManager());
        }

        // message 兜底：api 端无终止提示页，全局以 NullMessageResponder 占位，避免 message() 解析失败。
        if (!$container->has(MessageResponderInterface::class)) {
            $container->instance(MessageResponderInterface::class, new NullMessageResponder());
        }

        // ORM 连接解析器：Dou\Core\Orm\Model 经此取底层 Connection（DB 门面根实例）。
        Model::setConnectionResolver(function () {
            return DB::getFacadeRoot();
        });
    }

    /**
     * 通用工厂（File 是状态对象，Audit 等通过 helpers 解析依赖）。
     *
     * 调用前置：Connection / Request 等核心服务须已绑入容器（即先 instantiateCoreObjects 后再调用本方法）。
     *
     * @return void
     */
    protected function instantiateCommonFactories()
    {
        $this->loadFilesystemsConfig();
        $this->loadAiConfig();
        // 必须先于下方构造 AuditService((string) $request->ip()) —— 在第一处 ip() 读取前
        // 把可信代理名单灌入 Request，杜绝伪造 X-Forwarded-For 在管道前被采信。
        $this->loadSecurityConfig();
        $container = Container::getInstance();
        $fsManager = new FilesystemManager();
        $imageManager = new ImageManager();
        $container->instance(FilesystemManager::class, $fsManager);
        $container->instance(ImageManager::class, $imageManager);
        $container->instance(AttachmentService::class, new AttachmentService($fsManager, $imageManager));
        $container->instance(Zip::class, new Zip());
        $request = $container->make(Request::class);
        $container->instance(AuditService::class, new AuditService(
            (string) $request->ip(),
            $request
        ));
    }

    /**
     * 端 Init 注册自身 guard 到 AuthManager。
     *
     * 由三端 Init 在端侧 Auth Facade / AuthService 创建完成后调用：
     * - admin Init 在 bootCore 末尾调 `registerAuthGuard('admin', $adminAuth)`；
     * - front / api Init **无条件**先以 {@see \Dou\Core\Foundation\Auth\GuestGuard} 兜底
     *   注册同名 guard，再在 features.user 开关命中且 Auth Facade 类可加载时调用本方法
     *   覆盖为真实 Auth 实现（AuthManager::extend 自带 unset(resolved)，覆盖即生效）。
     *
     * 一并把 guard 实例本身注册到容器（按具体类名），方便 helper / 测试 swap。
     * 业务侧通过 `auth($guardName)` 显式解析 guard，无默认 guard。
     *
     * @param string $guardName guard 名（admin / front / api）
     * @param object $instance
     * @return void
     */
    protected function registerAuthGuard($guardName, $instance)
    {
        $container = Container::getInstance();
        if (!$container->has(AuthManager::class)) {
            $container->instance(AuthManager::class, new AuthManager());
        }
        /** @var AuthManager $mgr */
        $mgr = $container->make(AuthManager::class);

        $class = get_class($instance);
        $container->instance($class, $instance);

        $mgr->extend($guardName, function () use ($class) {
            return Container::getInstance()->make($class);
        });
    }

    /**
     * 定义 DOU_SHELL / DOU_ID / DOU_TOKEN 常量。
     *
     * 调用前必须已完成 db 对象绑定到容器（本方法以 DB::table('config') 读取 hash_code）。
     *
     * @param string $idPrefix DOU_ID 前缀（dou / admin / mini）
     * @param string $idSalt md5 salt（dou / admin / miniprogram）
     * @return void
     */
    protected function defineShellConstants($idPrefix, $idSalt)
    {
        if (!defined('DOU_SHELL')) {
            define('DOU_SHELL', DB::table('config')->where('name', 'hash_code')->value('value'));
        }
        if (!defined('DOU_ID')) {
            define('DOU_ID', $idPrefix . '_' . substr(md5(DOU_SHELL . $idSalt), 0, 5));
        }
        if (!defined('DOU_TOKEN')) {
            define('DOU_TOKEN', DOU_ID . '_remember_token');
        }
    }

    /**
     * 加载模块 lang 文件到容器单例 LangBag。
     *
     * lang 文件里以 $_LANG['key'] = 'value' 形式赋值，require 在本方法作用域内执行；
     * 加载完成后整表 replace 到 LangBag，业务侧通过 lang()/lang_set()/lang_has()/lang_all() 访问。
     *
     * @return void
     */
    protected function loadLanguageFiles()
    {
        $_LANG = array();
        foreach ((array) $this->languageManifest as $langFile) {
            require($langFile);
        }
        if (defined('IS_ADMIN') && constant('IS_ADMIN') && !isset($_LANG['miniprogram'])) {
            $_LANG['miniprogram'] = '微信小程序';
        }
        $container = Container::getInstance();
        if (!$container->has(LangBag::class)) {
            $container->instance(LangBag::class, new LangBag());
        }
        $container->make(LangBag::class)->replace($_LANG);
    }

    /**
     * 检查「会员衍生模块」是否在缺少会员模块的情况下被启用，并写入一次警告。
     *
     * order / vip / point / money / withdraw / share / favorites 等模块强依赖 user：
     * 若 `features.{name}` 为真而 `features.user` 为假，则记录 Log 警告便于运维定位
     * （阻断由 {@see Module::assertUserAvailable()} 在 Router 闸住，本方法仅记录）。
     *
     * @param array $featureNames 需要校验的模块短名列表；为空时回落 {@see Module::userDerivedFeatures()}
     * @return void
     */
    protected function warnFeaturesRequireUser(array $featureNames = array())
    {
        if (Config::get('features.user', false)) {
            return;
        }
        if (empty($featureNames)) {
            $featureNames = Module::userDerivedFeatures();
        }

        $missing = array();
        foreach ($featureNames as $name) {
            if (Config::get('features.' . $name, false)) {
                $missing[] = $name;
            }
        }

        if (empty($missing)) {
            return;
        }

        Log::warning('Features require user module but user is disabled', array(
            'channel' => 'system',
            'features' => $missing,
        ));
    }

    // -----------------------------------------------------------------
    // 日志与全局异常/致命错误
    // -----------------------------------------------------------------

    /**
     * 当前请求所属端（与三端入口常量一致）
     *
     * @return string front|admin|api
     */
    private function resolveLogRuntimeShell()
    {
        if (defined('IS_ADMIN') && constant('IS_ADMIN')) {
            return 'admin';
        }
        if (defined('IS_API') && constant('IS_API')) {
            return 'api';
        }
        return 'front';
    }

    /**
     * 某端是否允许在总开关打开时写运行时日志
     *
     * @param string $shell
     * @return bool
     */
    private static function logRuntimeShellIsEnabled($shell)
    {
        $map = self::$logRuntimeForShell;
        if (!is_array($map) || !isset($map[$shell])) {
            return true;
        }
        return (bool) $map[$shell];
    }

    /**
     * 初始化日志级别与全局异常/致命错误处理
     *
     * site.log_runtime 为全站总开关：false 时关闭 Log 并跳过全局钩子。
     * 总开关为 true 时：依本 Trait 内 {@see InitTrait::$logRuntimeForShell} 对应键为 false 则本端关闭；
     * 否则启用日志，写入 ROOT_PATH/data/log/{front|admin|api}/，并注册全局兜底钩子。
     * site.log_clean_keep_days：≤0 不自动清理；未配置时默认 30；≥1 时按该天数节流清理三端日志目录（约每 24 小时一次）。
     * 页面是否显示致命错误仍取决于 display_errors 等 PHP 配置。
     *
     * @return void
     */
    protected function initLogRuntime()
    {
        static $inited = false;
        if ($inited) {
            return;
        }
        $inited = true;

        // 全局兜底 handler 与「是否写运行期日志」解耦：无条件注册，
        // 即便 site.log_runtime 关闭或当前端日志禁用，也保留未捕获异常 / 致命错误的兜底
        // （site.debug 下渲染调试页、生产防白屏）。日志写入与否由下方 Log::setEnabled 单独控制。
        set_exception_handler(array($this, 'handleGlobalException'));
        register_shutdown_function(array($this, 'handleShutdownFatal'));

        Log::tryAutoCleanFromConfig(Config::get('site.log_clean_keep_days', 30));

        $globalOn = (bool) Config::get('site.log_runtime', true);
        if (!$globalOn) {
            Log::setEnabled(false);
            return;
        }

        $shell = $this->resolveLogRuntimeShell();
        $allowedShells = array('front', 'admin', 'api');
        if (!in_array($shell, $allowedShells, true)) {
            $shell = 'front';
        }

        if (!self::logRuntimeShellIsEnabled($shell)) {
            Log::setEnabled(false);
            return;
        }

        $isDebug = SiteDebugExceptionRenderer::isSiteDebugEnabled();
        Log::setEnabled(true);
        Log::setPath(STORAGE_PATH . 'log/' . $shell . '/');
        Log::setMinLevel($isDebug ? Log::DEBUG : Log::WARNING);

        // 非 debug：接管诊断级 PHP 错误（notice/warning/deprecated/strict），去重限流后落盘并吞掉
        // 默认输出（生产防 warning 文本泄漏到响应体）。debug 下不接管，交还 display_errors / Whoops 直接呈现。
        if (!$isDebug) {
            set_error_handler(array($this, 'handleRuntimeError'));
        }
    }

    /**
     * site.debug 为真时提高 PHP 错误可见性（与入口 SiteDebugExceptionRenderer 行为一致）。
     *
     * @return void
     */
    protected function applySiteDebugIni()
    {
        if (!SiteDebugExceptionRenderer::isSiteDebugEnabled()) {
            return;
        }
        if (function_exists('ini_set')) {
            ini_set('display_errors', '1');
        }
        error_reporting(E_ALL);

        // 全局兜底：仅 front/admin 常规 HTML 请求由 Whoops 接管 PHP 错误 / 未捕获异常。
        // API 与 Ajax/Accept:application/json 不注册，避免 PrettyPage 输出 HTML；
        // 此类请求仍由入口 try/catch 或 handleGlobalException 走 sendApi500。
        if (SiteDebugExceptionRenderer::shouldRegisterWhoopsGlobal()
            && class_exists('Dou\\Vendor\\Whoops\\Whoops')) {
            \Dou\Vendor\Whoops\Whoops::registerGlobal();
        }
    }

    /**
     * 记录未捕获异常，并在 site.debug 开启时输出调试页（HTML 兜底）。
     *
     * 一般入口 try-catch 已覆盖 Throwable / Exception；本方法兜底应对
     * 极少数发生在入口 catch 之外的未捕获异常，避免出现纯白屏。
     *
     * @param \Exception|\Throwable $e PHP 5.6 下为 Exception，7+ 下兼容 Error。
     * @return void
     */
    public function handleGlobalException($e)
    {
        Log::error('Uncaught exception', array(
            'channel' => 'system',
            'exception' => is_object($e) ? get_class($e) : 'Exception',
            'message' => is_object($e) ? $e->getMessage() : '',
            'file' => is_object($e) ? $e->getFile() : '',
            'line' => is_object($e) ? (int) $e->getLine() : 0,
            'trace' => is_object($e) ? substr($e->getTraceAsString(), 0, 4000) : '',
        ));

        if (SiteDebugExceptionRenderer::isSiteDebugEnabled()
            && SiteDebugExceptionRenderer::isThrowableLike($e)) {
            if (SiteDebugExceptionRenderer::isJsonLikeRequest()) {
                SiteDebugExceptionRenderer::sendApi500($e);
                exit;
            }
            SiteDebugExceptionRenderer::renderAndExitForHtml(
                $e,
                SiteDebugExceptionRenderer::resolveDebugChannel()
            );
        }
    }

    /**
     * 记录致命错误（白屏场景）
     *
     * @return void
     */
    public function handleShutdownFatal()
    {
        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }

        $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        Log::critical('Fatal error', array(
            'channel' => 'system',
            'type' => (int) $error['type'],
            'message' => isset($error['message']) ? $error['message'] : '',
            'file' => isset($error['file']) ? $error['file'] : '',
            'line' => isset($error['line']) ? (int) $error['line'] : 0,
        ));
    }

    /**
     * 非 debug 下接管诊断级 PHP 错误（notice / warning / deprecated / strict）：去重限流后写入
     * 运行期日志，并返回 true 阻断默认输出（生产防 warning 文本泄漏）。
     *
     * 仅由 initLogRuntime 在非 debug 且日志启用时注册；致命级与被 @ 抑制 / 不在 error_reporting
     * 级别内的错误一律返回 false 交还 PHP 默认处理（致命级最终由 handleShutdownFatal 兜底）。
     *
     * @param int $errno
     * @param string $errstr
     * @param string $errfile
     * @param int $errline
     * @return bool true 表示已接管；false 交还 PHP 默认处理
     */
    public function handleRuntimeError($errno, $errstr, $errfile = '', $errline = 0)
    {
        // honor @ 抑制符与当前 error_reporting 级别：被抑制者返还 PHP 默认处理，不改变全站 @ 语义。
        if ((error_reporting() & $errno) === 0) {
            return false;
        }

        $diagnostic = array(
            E_NOTICE, E_USER_NOTICE,
            E_WARNING, E_USER_WARNING,
            E_DEPRECATED, E_USER_DEPRECATED,
            E_STRICT,
        );
        if (!in_array($errno, $diagnostic, true)) {
            return false;
        }

        $this->logRuntimeDiagnostic((int) $errno, (string) $errstr, (string) $errfile, (int) $errline);

        return true;
    }

    /**
     * 落盘单条运行期诊断错误，带 per-request 去重与上限，避免遗留代码在 8.x 触发的同类
     * warning 洪泛刷爆日志。统一以 warning 级写入（非 debug 的 minLevel 为 WARNING），
     * 真实 PHP 错误类型记入 type 字段。
     *
     * @param int $errno
     * @param string $errstr
     * @param string $errfile
     * @param int $errline
     * @return void
     */
    private function logRuntimeDiagnostic($errno, $errstr, $errfile, $errline)
    {
        static $seen = array();
        static $count = 0;

        if ($count >= 200) {
            return;
        }
        $signature = $errno . '|' . $errfile . '|' . $errline . '|' . $errstr;
        if (isset($seen[$signature])) {
            return;
        }
        $seen[$signature] = true;
        $count++;

        Log::warning('PHP runtime diagnostic', array(
            'channel' => 'system',
            'type' => $errno,
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
        ));
    }
}
