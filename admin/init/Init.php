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

namespace Dou\Admin\Init;

use Dou\Admin\Contract\AdminLanguageContract;
use Dou\Admin\Http\AdminMessageResponder;
use Dou\Admin\Service\Auth\AuthService;
use Dou\Admin\Service\Cache\CacheClearService;
use Dou\Admin\Service\Menu\AdminMenuService;
use Dou\Admin\Service\Nav\NavCategorySyncService;
use Dou\Admin\Service\Theme\ThemeSettingsReader;
use Dou\Admin\Service\Workspace\UpdateBadgeBuilder;
use Dou\Admin\Service\Workspace\WorkspaceBuilder;
use Dou\Core\Bootstrap\SiteBootstrap;
use Dou\Core\Bootstrap\SystemBootstrap;
use Dou\Core\Contract\PluginServiceContract;
use Dou\Core\Facade\Session;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Container\Container;
use Dou\Core\Foundation\Provider\ProviderRegistry;
use Dou\Core\Init\InitTrait;
use Dou\Core\Service\Nav\MiniprogramNavigationBuilder;
use Dou\Core\Service\System\ModuleSettingReader;
use Dou\Core\Support\Util;
use Dou\Core\Support\ViewVars;
use Dou\Core\Web\I18n\JsLangExporter;
use Dou\Core\Web\Routing\JsRouteExporter;
use Dou\Core\Web\Template\DouView;
use Dou\Core\Web\Template\Prefilter\AdminPrefilter;
use Dou\Core\Web\Template\TemplateRendererInterface;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台初始化类
 *
 * 封装后台启动逻辑，通过 boot() 实例方法调用，按序完成核心对象、视图引擎、模块装配。
 * 业务侧通过 helper / 门面（DB::、auth('admin')、message()、route()、Config::、locale() 等）就近读取依赖。
 */
class Init
{
    use InitTrait;

    /**
     * 后台启动入口
     *
     * @return void
     */
    public function boot()
    {
        $this->startSession();
        $this->setErrorReporting();
        $this->setTimezone();
        $this->defineAdminConstants();

        $this->bootCore();
        $this->setupViewEngine();
        $this->loadModules();

        ob_start();
    }

    /**
     * 定义后台专用常量
     *
     * @return void
     */
    private function defineAdminConstants()
    {
        if (!defined('IS_ADMIN')) {
            define('IS_ADMIN', true);
        }

        // 读取 config/admin_dir.php 中的 $admining（自定义后台目录）
        if (file_exists(CONFIG_PATH . 'admin_dir.php')) {
            include_once(CONFIG_PATH . 'admin_dir.php');
            if (isset($admining) && !file_exists(ROOT_PATH . $admining)) {
                unset($admining);
            }
        }

        if (!defined('ADMIN_DIR')) {
            define('ADMIN_DIR', isset($GLOBALS['admining']) ? $GLOBALS['admining'] : 'admin');
        }
        if (!defined('M_DIR')) {
            define('M_DIR', 'm');
        }
        if (!defined('MINIPROGRAM_DIR')) {
            define('MINIPROGRAM_DIR', 'miniprogram');
        }
        if (!defined('API_DIR')) {
            define('API_DIR', 'api');
        }
    }

    /**
     * 加载 config/cloud.php 至 Config（云服务 API Base URL 等；仅后台使用）
     *
     * @return void
     */
    private function loadCloudConfig()
    {
        $path = CONFIG_PATH . 'cloud.php';
        if (file_exists($path)) {
            $a = include $path;
            if (is_array($a) && isset($a['cloud'])) {
                Config::set('cloud', $a['cloud']);
            }
        }
    }

    /**
     * 实例化核心对象、注册端侧门面、定义站点常量。
     *
     * @return void
     */
    private function bootCore()
    {
        $this->instantiateCoreObjects();

        $container = Container::getInstance();

        // 云服务配置仅后台需要（扩展中心等）
        $this->loadCloudConfig();

        $this->instantiateCommonFactories();

        $adminAuth = new AuthService();
        $this->registerAuthGuard('admin', $adminAuth);
        ProviderRegistry::registerAll($container);
        $adminLanguage = $container->make(AdminLanguageContract::class);
        $container->instance(AdminLanguageContract::class, $adminLanguage);
        $container->instance(\Dou\Core\Contract\LanguageContract::class, $adminLanguage);

        // 通用 Domain 服务
        $container->instance(PluginServiceContract::class, $container->make(PluginServiceContract::class));
        $container->instance(MiniprogramNavigationBuilder::class, new MiniprogramNavigationBuilder());

        // 后台「提示页 + 跳转 + 终止」实现
        $messageResponder = new AdminMessageResponder();
        $container->instance(AdminMessageResponder::class, $messageResponder);
        $container->instance(\Dou\Core\Contract\MessageResponderInterface::class, $messageResponder);

        $this->defineShellConstants('admin', 'admin');

        // 计算 ROOT_URL（后台需要剥离 ADMIN_DIR）
        $rootUrl = preg_replace('/' . ADMIN_DIR . '\//Ums', '', dirname(HTTP . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']) . '/');
        define('ROOT_URL', $rootUrl);
        define('SITE_URL', ROOT_URL);
        define('M_URL', ROOT_URL . M_DIR . '/');
        define('HOME_URL', ROOT_URL);
        define('ADMIN_URL', ROOT_URL . ADMIN_DIR . '/');
        define('API_URL', ROOT_URL . API_DIR . '/');
        define('PLUGIN_URL', ROOT_URL . 'plugin/');

        // 同步请求级 base URL（仅 SiteConfigAssembler 作为 root_url fallback 消费）
        if ($container->has(\Dou\Core\Web\Http\Request::class)) {
            $container->make(\Dou\Core\Web\Http\Request::class)->setBaseUrl($rootUrl);
        }

        // 页面头
        header('Content-type: text/html; charset=' . DOU_CHARSET);
        header('Expires: Fri, 14 Mar 1980 20:53:00 GMT');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
    }

    /**
     * 初始化视图引擎并填充基础配置字段
     *
     * @return void
     */
    private function setupViewEngine()
    {
        $engine = new DouView();
        $engine->template_dir = ADMIN_PATH . 'view';
        $engine->compile_dir = STORAGE_PATH . 'cache/template/' . ADMIN_DIR;
        $engine->leftDelimiter = '{';
        $engine->rightDelimiter = '}';
        $engine->setEscapeHtml(true);
        $engine->registerPrefilter(array(AdminPrefilter::class, 'apply'));

        if (!file_exists($engine->compile_dir)) {
            mkdir($engine->compile_dir, 0777, true);
        }

        Container::getInstance()->instance(DouView::class, $engine);
        // 同时按接口注册，供 ViewResponse / view() / BaseController::view() 面向接口解耦
        Container::getInstance()->instance(TemplateRendererInterface::class, $engine);

        SiteBootstrap::loadSite(false, defined('ROOT_URL') ? (string) ROOT_URL : '');
        $this->initLogRuntime();
        $this->applySiteDebugIni();
        SiteBootstrap::loadParameter();

        $engine->assign('site', Config::get('site', array()));
        $engine->assign('admin_url', defined('ADMIN_URL') ? ADMIN_URL : '');
        // param 在 loadModules 中 Config::set 后再次 assign，避免模板读到过时参数
    }

    /**
     * 加载模块、语言包及可选服务，装配后台模板侧通用变量。
     *
     * @return void
     */
    private function loadModules()
    {
        SiteBootstrap::loadParameter();
        $defaultLanguage = Config::get('site.language', '') ?: 'zh_cn';
        $adminLangCandidate = $defaultLanguage . '/admin';
        $langPack = file_exists(ROOT_PATH . 'languages/' . $adminLangCandidate)
            ? $adminLangCandidate
            : 'zh_cn/admin';
        $boot = SystemBootstrap::loadCore(array(
            'isAdmin' => true,
            'langPack' => $langPack,
            'includeAdminSort' => true,
            'adminSort' => Session::has('sort') ? (bool) Session::get('sort') : false,
        ));
        $module = isset($boot['module']) ? $boot['module'] : array();
        $features = isset($boot['features']) ? $boot['features'] : array();
        $features['slug'] = Config::get('site.route_column', '') === 'prefixed_alias';
        Config::set('module', $module);
        Config::set('system', isset($boot['system']) ? $boot['system'] : array());
        Config::set('features', $features);
        $this->languageManifest = isset($boot['lang']) ? (array) $boot['lang'] : array();
        Config::set('pagination', !empty(Config::get('site.display', '')) ? unserialize(Config::get('site.display', '')) : array());
        Config::set('defined', !empty(Config::get('site.defined', '')) ? unserialize(Config::get('site.defined', '')) : array());

        // 会员衍生模块依赖检查（清单由 Module::userDerivedFeatures() 提供；阻断由 Router 闸住，本处仅记录警告）。
        $this->warnFeaturesRequireUser();

        // features.language 此刻已生效，重新解析后台语言契约：开启且模块在 → 真实现；否则保留 Null
        // Init.bootCore 早期已 instance() 锁了 Null 占位（且按容器「instance 覆盖 factory」语义清掉了原 factory），
        // 这里必须重新跑一次 ProviderRegistry 注册回 factory，才能让 make() 基于最新 features 拉到真实现。
        // 重注册 factory 也会一并清掉 instantiateCoreObjects 阶段 instance() 锁住的 PluginServiceContract 单例，
        // 因此随即把它重新 make + instance 回单例，避免 plugin() 之后每次走 factory 生成新实例。
        $container = Container::getInstance();
        ProviderRegistry::registerAll($container);
        $adminLanguage = $container->make(AdminLanguageContract::class);
        $container->instance(AdminLanguageContract::class, $adminLanguage);
        $container->instance(\Dou\Core\Contract\LanguageContract::class, $adminLanguage);
        $container->instance(PluginServiceContract::class, $container->make(PluginServiceContract::class));

        $this->loadLanguageFiles();

        // 导航同步 / 清缓存（依赖 language->deleteLang 的须在 language 就绪后注册）
        $container->instance(NavCategorySyncService::class, new NavCategorySyncService());
        $container->instance(CacheClearService::class, new CacheClearService());

        // 会员模块（类由云模块安装到 core/service/user/，未安装时跳过）
        if (!empty($features['user'])) {
            $userService = \Dou\Core\Foundation\Extension\Module::make('user');
            if ($userService !== null) {
                $container->instance(\Dou\Core\Service\User\UserService::class, $userService);
            }
            $container->instance(
                \Dou\Admin\Service\User\UserCenterNavBuilder::class,
                new \Dou\Admin\Service\User\UserCenterNavBuilder()
            );
        }

        // 短信服务（仅在 core/library/sms 存在时给模板打开短信验证码开关，
        // 实际客户端由 \Dou\Vendor\Sms\Sms 在使用方按需创建）
        if (file_exists(LIBRARY_PATH . 'sms/Sms.php')) {
            app(DouView::class)->assign('sms_captcha', true);
        }

        // 核心扩展
        if (file_exists($f = CORE_PATH . 'core.php')) {
            require($f);
        }

        // 授权检测
        $this->detectAuthorization();

        // 工作台 / 更新角标 / 主题配置 ViewModel：lang 与 detectAuthorization 完成后注册，AuthMiddleware 复用
        $moduleSettingReader = $container->make(ModuleSettingReader::class);
        $container->instance(ModuleSettingReader::class, $moduleSettingReader);
        $container->instance(WorkspaceBuilder::class, new WorkspaceBuilder(
            $moduleSettingReader,
            $container->make(AdminMenuService::class)
        ));
        $container->instance(UpdateBadgeBuilder::class, new UpdateBadgeBuilder());
        $container->instance(ThemeSettingsReader::class, new ThemeSettingsReader());

        $settingForView = $module;
        $themeSetting = $container->make(ThemeSettingsReader::class)->read();
        // 后台各模块缩略图提示依赖 theme.*_img_size；主题 ..setting.php 缺行时补空串，避免 PHP 8 未定义下标
        $themeFromFile = (is_array($themeSetting) && isset($themeSetting['theme']) && is_array($themeSetting['theme']))
            ? $themeSetting['theme']
            : array();
        $settingForView['theme'] = ViewVars::theme($themeFromFile);

        $engine = app(DouView::class);
        $engine->assign('setting', $settingForView);
        $engine->assign('width', '');
        $engine->assign('js', '');
        $engine->assign('paid_use', false);
        $engine->assign('ur_here', '');
        $engine->assign('sub_cur', '');
        $engine->assign('cue_open_ssl', false);
        $engine->assign('data_list', array());
        $engine->assign('cloud_list_class', '');

        // 主题路径：后台不应用授权降级（设置页等"看原值"场景），SiteConfigAssembler 已写好
        // site.theme_url；本端仅当 site.site_theme 非空时同步一次。
        if (!empty(Config::get('site.site_theme', ''))) {
            $themeUrl = ROOT_URL . 'theme/' . Config::get('site.site_theme', '') . '/';
            Config::set('site.theme_url', $themeUrl);
        }
        $engine->assign('theme_path', Config::get('site.theme_url', ''));

        $staticAdminToken = (string) csrf()->ensure('static_admin');
        $engine->assign('lang', lang_all());
        $engine->assign('token', $staticAdminToken);
        $engine->assign('csrf_token', $staticAdminToken);
        $engine->assign('thumb_crop', Session::get('thumb_crop', 0) ? 1 : 0);
        $engine->assign('gallery_crop', Session::get('gallery_crop', 0) ? 1 : 0);
        $engine->assign('features', ViewVars::features(Config::get('features', array())));
        $engine->assign('_SYSTEM_SIGN', SYSTEM_SIGN);
        $workspaceRequest = $container->make(\Dou\Core\Web\Http\Request::class);
        $engine->assign('workspace', $container->make(WorkspaceBuilder::class)->build(
            (string) $workspaceRequest->routeModule(),
            (string) $workspaceRequest->route('category_id', '')
        ));
        $updateBadge = $container->make(UpdateBadgeBuilder::class)->build();
        if ($updateBadge !== null) {
            $engine->assign('unum', $updateBadge);
        }

        if (Config::get('site.ssl', false) && HTTP !== 'https://') {
            $engine->assign('cue_open_ssl', true);
            $engine->assign('ssl_url', 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
        }

        // loadModules 内已 Config::set('param')，与视图引擎同步（setupViewEngine 阶段 param 可能未含模块设置）
        $engine->assign('param', ViewVars::param(Config::get('param', array())));
        $engine->assign('js_route_config_json', JsRouteExporter::toJsonScript(JsRouteExporter::buildAdminConfig()));
        $engine->assign('js_routes_script_url', route('admin.tool.routes_js', array(), array('query' => array('v' => JsRouteExporter::manifestUrlVersion('Admin')))));
        $langPack = (string) Config::get('site.language', 'zh_cn') . '/admin';
        $engine->assign('js_lang_script_url', route('admin.tool.lang_js', array(), array('query' => array('v' => JsLangExporter::manifestUrlVersion('admin', $langPack)))));
    }

    /**
     * 授权检测（pure_mode / partner_authorized 在授权通过后生效）。
     *
     * 检测结果落入 `Config::set('app.licensed', bool)`，业务侧通过
     * `Config::get('app.licensed', false)` 读取。
     *
     * @return void
     */
    private function detectAuthorization()
    {
        Config::set('app.licensed', false);
        $engine = app(DouView::class);
        $engine->assign('pure_mode', false);

        if (file_exists($cdkeyFile = CONFIG_PATH . 'cdkey.php')) {
            // include_once 在本方法内执行；..cdkey.php 顶层声明的 $_CDKEY / $_PARTNER_AUTHORIZED
            // 按 PHP include 作用域规则进入本方法局部作用域，不会出现在 $GLOBALS 中。
            include_once($cdkeyFile);
            $cdkey = isset($_CDKEY) ? $_CDKEY : array();
            $decompileInit = Util::fromCharCodes($cdkey);
            $rootUrl = defined('ROOT_URL') ? ROOT_URL : '';
            if ($decompileInit === substr(md5(DOU_SHELL), 16) . Config::get('site.douphp_version', '') . Util::normalizeUrlHost($rootUrl)) {
                Config::set('app.licensed', true);
                if (!empty(Config::get('site.pure_mode', ''))) {
                    lang_set('home', preg_replace('/DouPHP /Ums', '', lang('home')));
                    lang_set('login', preg_replace('/DouPHP /Ums', '', lang('login')));
                    $engine->assign('pure_mode', true);
                }
                if (!empty($_PARTNER_AUTHORIZED)) {
                    $engine->assign('partner_authorize', true);
                }
            }
        }

        $engine->assign('cloud_vip', Config::get('app.licensed', false));
    }
}
