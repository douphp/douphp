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

namespace Dou\Front\Init;

use Dou\Core\Bootstrap\SiteBootstrap;
use Dou\Core\Bootstrap\SystemBootstrap;
use Dou\Core\Contract\LanguageContract;
use Dou\Core\Contract\PluginServiceContract;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Auth\GuestGuard;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Container\Container;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Foundation\Provider\ProviderRegistry;
use Dou\Core\Init\InitTrait;
use Dou\Core\Service\Nav\MiniprogramNavigationBuilder;
use Dou\Core\Service\Theme\SiteThemePolicy;
use Dou\Core\Support\Honeypot;
use Dou\Core\Support\Util;
use Dou\Core\Support\ViewVars;
use Dou\Core\Utility\Fix;
use Dou\Core\Web\Http\HttpResponseException;
use Dou\Core\Web\I18n\JsLangExporter;
use Dou\Core\Web\Routing\JsRouteExporter;
use Dou\Core\Web\Template\DouView;
use Dou\Core\Web\Template\Prefilter\FrontPrefilter;
use Dou\Core\Web\Template\TemplateRendererInterface;
use Dou\Front\Controller\Asset\LangController;
use Dou\Front\Http\FrontMessageResponder;
use Dou\Front\Model\Box\Box;
use Dou\Front\Model\Fragment\Fragment;
use Dou\Front\Model\Link\Link;
use Dou\Front\Model\Page\Page;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SchemaService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台初始化类。
 *
 * 封装前台启动逻辑，通过 boot() 实例方法调用。
 * 业务侧通过 helper / 门面（DB::、auth('front')、message()、route()、Config::、locale() 等）
 * 就近读取依赖。
 */
class Init
{
    use InitTrait;

    /**
     * 前台启动入口
     *
     * @param array $routeInfo 由 Router 解析后传入的路由信息 ['lang' => ..., 'is_home' => ...]
     * @return void
     */
    public function boot(array $routeInfo = array())
    {
        $this->bootCommon($routeInfo);
        $this->bootCore();
        // 先建立视图引擎（依赖 cfg，cfg 已在 bootCore 就绪）；
        // 让后续 loadLanguageAndModules 里的 data/user 等能真正向模板注入变量。
        $this->setupViewEngine();
        $this->loadLanguageAndModules();
        $this->assignCommonViewVars();
        $this->checkSiteClosed();

        ob_start();

        // 支付对账兜底触发器：默认 1% 概率抽签，命中后通过 shutdown 钩子在响应输出之后异步执行，
        // 装了 cron 的用户可在配置面板把概率分母调到 0 关闭本兜底。
        $this->tryFirePaymentReconciliationLottery();
    }

    /**
     * 抽签触发支付对账兜底：本身仅做一次 mt_rand 抽签，未命中立刻返回；
     * 命中后注册 shutdown 钩子执行 flock 防并发的对账动作，对当前请求性能影响极小。
     *
     * @return void
     */
    private function tryFirePaymentReconciliationLottery()
    {
        try {
            $container = Container::getInstance();
            $lottery = $container->make(\Dou\Front\Service\Order\PaymentReconciliationLottery::class);
            $lottery->tryTrigger();
        } catch (\Exception $e) {
            // 抽签触发器与主请求解耦，任何异常都吃掉，绝不影响前台输出
        }
    }

    /**
     * 插件独立入口（return_url、notify_url 等不经前台 Router）
     *
     * 完成与 {@see boot()} 相同的数据库与 bootCore 阶段，但不初始化视图引擎/主题模板，
     * 仅加载语言包、模块 init、会员会话等，供支付回调脚本使用。
     *
     * 在 require 本方法前可为异步通知定义常量 DOU_PLUGIN_NOTIFY 为 true，
     * 以避免发送 text/html 头（便于网关验响应体）。
     *
     * 脚本需在加载 core/bootstrap.php 之前定义 IN_DOUCO。
     * 启动早期 baseUrl 随当前脚本路径变化（用于安装检测等）；站点对外根地址在 bootCore 后
     * 以站点配置为准（如 Config::get('site.root_url')）。
     * 支付回调等对外 URL 请在后台站点地址或网关中配置，勿依赖本处路径裁剪。
     *
     * @param array $routeInfo 与 boot() 相同；无语言前缀时可传 array()
     * @return void
     */
    public function bootForPluginEntry(array $routeInfo = array())
    {
        $this->bootCommon($routeInfo);
        $this->bootCore();
        $this->loadLanguageAndModules();
        $this->checkSiteClosed();

        if (defined('DOU_PLUGIN_NOTIFY') && constant('DOU_PLUGIN_NOTIFY')) {
            header('Cache-control: no-store, no-cache, must-revalidate');
        } else {
            header('Cache-control: private');
            header('Content-type: text/html; charset=' . DOU_CHARSET);
        }

        ob_start();
    }

    // -----------------------------------------------------------------------
    // 公共初始化步骤
    // -----------------------------------------------------------------------

    /**
     * 执行两个入口共享的基础初始化步骤
     *
     * @param array $routeInfo
     * @return void
     */
    private function bootCommon(array $routeInfo)
    {
        $this->startSession();
        $this->setErrorReporting();
        $this->setTimezone();

        $this->computeRootUrl();
        $this->resolveCurLang($routeInfo);
        $this->loadCustomFile();
    }

    /**
     * 解析当前语言（支持多语言前缀或 ?lang= 两种形式），结果写入 Locale 单例。
     *
     * @param array $routeInfo
     * @return void
     */
    private function resolveCurLang(array $routeInfo)
    {
        $locale = locale();
        $langFromRoute = isset($routeInfo['lang']) ? $routeInfo['lang'] : null;
        if ($langFromRoute) {
            $pack = str_replace('-', '_', $langFromRoute);
            $locale->set('rewrite_open', $langFromRoute, $pack);
        } elseif (!empty($_GET['lang']) && preg_match('/^[a-zA-Z]{2}_[a-zA-Z]{2}$/', $_GET['lang'])) {
            $locale->set('rewrite_close', $_GET['lang'], $_GET['lang']);
        } else {
            $locale->reset();
        }
    }

    // -----------------------------------------------------------------------
    // 核心对象实例化
    // -----------------------------------------------------------------------

    /**
     * 实例化核心对象、注册端侧门面、定义站点常量。
     *
     * @return void
     */
    private function bootCore()
    {
        $this->instantiateCoreObjects();

        $container = Container::getInstance();

        // 语言契约（Provider 解析；features 尚未就绪时落到 Null 占位，下方 features 设置后再 make）
        ProviderRegistry::registerAll($container);
        $container->instance(LanguageContract::class, $container->make(LanguageContract::class));

        $this->instantiateCommonFactories();

        SiteBootstrap::loadSite(true, (string) request()->baseUrl());
        $this->initLogRuntime();
        $this->applySiteDebugIni();

        $this->defineShellConstants('dou', 'dou');

        define('ROOT_URL', Config::get('site.root_url', '/'));
        define('HOME_URL', Config::get('site.home_url', ROOT_URL));
        define('M_URL', Config::get('site.m_url', ROOT_URL . M_DIR . '/'));
        define('PLUGIN_URL', ROOT_URL . 'plugin/');

        $defaultLanguage = Config::get('site.language', '') ?: 'zh_cn';
        $langPack = locale()->isActive() ? locale()->pack() : $defaultLanguage;
        $boot = SystemBootstrap::loadCore(array(
            'isAdmin' => false,
            'langPack' => $langPack,
            'includeAdminSort' => false,
            'adminSort' => false,
        ));

        SiteBootstrap::loadParameter();
        Config::set('module', isset($boot['module']) ? $boot['module'] : array());
        Config::set('system', isset($boot['system']) ? $boot['system'] : array());
        Config::set('features', isset($boot['features']) ? $boot['features'] : array());
        $this->languageManifest = isset($boot['lang']) ? (array) $boot['lang'] : array();
        Config::set('pagination', !empty(Config::get('site.display', '')) ? unserialize(Config::get('site.display', '')) : array());
        Config::set('defined', !empty(Config::get('site.defined', '')) ? unserialize(Config::get('site.defined', '')) : array());

        // 会员衍生模块依赖检查：清单由 Module::userDerivedFeatures() 提供，阻断由 Router::dispatch 上的 Module::assertUserAvailable() 负责；
        // 这里仅记录警告，不阻断启动。
        $this->warnFeaturesRequireUser();

        // features.language 此刻已生效，重新解析语言契约：开启且模块在 → 真实现；否则保留 Null
        // bootCore 早期已 instance() 锁了 Null 占位（且按容器「instance 覆盖 factory」语义清掉了原 factory），
        // 这里必须重新跑 ProviderRegistry 注册回 factory，才能让 make() 基于最新 features 拉到真实现。
        ProviderRegistry::registerAll($container);
        $container->instance(LanguageContract::class, $container->make(LanguageContract::class));

        // 通用 Domain 服务
        $container->instance(PluginServiceContract::class, $container->make(PluginServiceContract::class));
        $container->instance(MiniprogramNavigationBuilder::class, new MiniprogramNavigationBuilder());

        // 结构化数据标记（BreadcrumbBuilder 通过构造注入依赖 SchemaService，必须先注册）
        $schemaService = new SchemaService();
        $container->instance(SchemaService::class, $schemaService);

        // 前台 ViewModel/Responder
        $nav = new NavigationBuilder();
        $breadcrumbBuilder = new BreadcrumbBuilder($schemaService);
        $messageResponder = new FrontMessageResponder($nav, $breadcrumbBuilder);
        $container->instance(NavigationBuilder::class, $nav);
        $container->instance(BreadcrumbBuilder::class, $breadcrumbBuilder);
        $container->instance(FrontMessageResponder::class, $messageResponder);
        $container->instance(\Dou\Core\Contract\MessageResponderInterface::class, $messageResponder);
        $container->instance(\Dou\Front\Service\Seo\SeoResolver::class, new \Dou\Front\Service\Seo\SeoResolver());
        $container->instance(\Dou\Front\Service\Sort\ListSortOptionBuilder::class, new \Dou\Front\Service\Sort\ListSortOptionBuilder());
    }

    // -----------------------------------------------------------------------
    // 语言包与模块初始化
    // -----------------------------------------------------------------------

    /**
     * 加载语言包、执行模块初始化文件、初始化可选服务。
     *
     * @return void
     */
    private function loadLanguageAndModules()
    {
        if (defined('EXIT_INIT')) {
            return;
        }

        // 多语言验证
        $locale = locale();
        if ($locale->isActive()) {
            if (!DB::table('language')->where('language_pack', $locale->pack())->find() || !Config::get('features.language', false)) {
                throw new HttpResponseException(redirect(ROOT_URL));
            } elseif ($locale->mode() === 'rewrite_open' && !Config::get('site.rewrite', false)) {
                throw new HttpResponseException(redirect(ROOT_URL));
            }
        }

        // 强制 HTTPS
        if (Config::get('site.ssl', false) && HTTP !== 'https://') {
            header('HTTP/1.1 301 Moved Permanently');
            header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
            exit;
        }

        // 授权检测：结果落入 Config::set('app.licensed', bool)
        Config::set('app.licensed', false);
        if (file_exists($cdkeyFile = CONFIG_PATH . 'cdkey.php')) {
            // include_once 在本方法内执行；..cdkey.php 顶层声明的 $_CDKEY
            // 按 PHP include 作用域规则进入本方法局部作用域，不会出现在 $GLOBALS 中。
            include_once($cdkeyFile);
            $cdkey = isset($_CDKEY) ? $_CDKEY : array();
            $decompileInit = Util::fromCharCodes($cdkey);
            if ($decompileInit === substr(md5(DOU_SHELL), 16) . Config::get('site.douphp_version', '') . Util::normalizeUrlHost(ROOT_URL)) {
                Config::set('app.licensed', true);
            }
        }

        // 版权信息 / 语言包
        $powerText = Util::fromCharCodes(array(0x50, 0x6f, 0x77, 0x65, 0x72, 0x65, 0x64, 0x20, 0x62, 0x79));
        $this->loadLanguageFiles();
        lang_set('copyright', lang_has('copyright')
            ? preg_replace('/d%/Ums', Config::get('site.site_name', ''), lang('copyright'))
            : '');
        lang_set('powered_by', lang_has('powered_by')
            ? preg_replace('/d%/Ums', $powerText, lang('powered_by'))
            : '');

        $container = Container::getInstance();

        // 前台 auth guard：先以 GuestGuard 兜底，user 模块就位时再覆盖为真实 Auth Facade。
        // 保证未装 user 的最小化站点上 `auth('front')` 也能解析到合法只读实例。
        $this->registerAuthGuard('front', new GuestGuard());

        if (Config::get('features.user', false)) {
            $container->instance(
                \Dou\Front\Service\User\UserCenterNavBuilder::class,
                new \Dou\Front\Service\User\UserCenterNavBuilder()
            );
        }

        if (Config::get('app.licensed', false)) {
            lang_set('powered_by', '');
        }

        $hasViewEngine = $container->has(DouView::class);

        if ($hasViewEngine) {
            $engine = app(DouView::class);

            if (Config::get('features.data', false)) {
                $dataResult = data()->get();
                $engine->assign('data', $dataResult);
                if (!Config::get('features.fragment', false)) {
                    $engine->assign('fragment', $dataResult);
                }
            } elseif (Config::get('features.fragment', false)) {
                $engine->assign('fragment', Fragment::fragment());
            }

            if (Config::get('features.box', false)) {
                $engine->assign('box_list', Box::box());
            }
        }

        // 会员模块（核心实现类由云模块安装，未安装时跳过；通过 Module::make 解析以触发容器构造注入）
        // 注：'front' guard 已在前面以 GuestGuard 兜底注册，本块负责 user 模块就绪后的
        // 「真实 Auth 覆盖 + 身份恢复 + 业务数据查询」。
        $userProfile = array();
        $vipDetail = null;
        $workDetail = array();
        $distributionDetail = null;
        $authState = array();
        if (Config::get('features.user', false)) {
            $userService = Module::make('user');
            if ($userService !== null) {
                $container->instance(\Dou\Core\Service\User\UserService::class, $userService);
            }

            $authClass = 'Dou\\Front\\Facade\\Auth';
            $auth = class_exists($authClass) ? new $authClass() : null;
            if (is_object($auth)) {
                $this->registerAuthGuard('front', $auth);
                if (method_exists($auth, 'hydrate')) {
                    call_user_func(array($auth, 'hydrate'));
                }

                $user = user();
                $userId = (int) $auth->id();
                $userProfile = method_exists($auth, 'user') ? call_user_func(array($auth, 'user')) : array();
                if (!is_array($userProfile)) {
                    $userProfile = array();
                }
                $workDetail = method_exists($auth, 'work') ? call_user_func(array($auth, 'work')) : array();
                $vipDetail = (is_object($user) && method_exists($user, 'vip'))
                    ? call_user_func(array($user, 'vip'), $userId)
                    : null;
                $distributionDetail = (is_object($user) && method_exists($user, 'distribution'))
                    ? call_user_func(array($user, 'distribution'), $userId)
                    : null;
                $authState = is_object($user)
                    ? $container->make(\Dou\Front\Service\View\UserStateBuilder::class)->build($userId)
                    : array(
                        'is_login' => $userId > 0,
                        'is_vip' => false,
                        'is_work' => false,
                        'is_distribution' => false,
                    );
            }
        }

        // 模板命名空间 $dou：无论 user 模块是否安装都装配，未装 / 未登录均落空骨架。
        // 基础模块（首页 / 产品 / 文章 / 列表）的 .tpl 直接读 {$dou.auth.is_login} 等，
        // 需要可预测的默认值，避免 PHP 8 + 模板严格模式下抛 "undefined index"。
        if ($container->has(DouView::class)) {
            $douRaw = array(
                'user' => $userProfile,
                'auth' => is_array($authState) ? $authState : array(),
                'vip' => is_array($vipDetail) ? $vipDetail : array(),
                'work' => is_array($workDetail) ? $workDetail : array(),
                'distribution' => is_array($distributionDetail) ? $distributionDetail : array(),
            );
            app(DouView::class)->assign('dou', ViewVars::dou($douRaw));

            // 兼容模板中以 $global_user.xxx 形态访问会员数据的写法（推荐统一走 $dou 命名空间）。
            $legacyGlobalUser = $userProfile;
            $legacyGlobalUser['vip'] = is_array($vipDetail) ? $vipDetail : false;
            $legacyGlobalUser['work'] = !empty($workDetail) ? $workDetail : null;
            $legacyGlobalUser['distribution'] = is_array($distributionDetail) ? $distributionDetail : false;
            app(DouView::class)->assign('global_user', $legacyGlobalUser);
        }

        // 短信服务（仅在 core/library/sms 存在时给模板打开短信验证码开关，
        // 实际客户端由 \Dou\Vendor\Sms\Sms 在使用方按需创建）
        if (file_exists(LIBRARY_PATH . 'sms/Sms.php')) {
            if ($container->has(DouView::class)) {
                app(DouView::class)->assign('sms_captcha', true);
            }
        }

        // 核心扩展加载文件
        if (file_exists($f = ROOT_PATH . 'include/core.load.php')) {
            require($f);
        }
    }

    // -----------------------------------------------------------------------
    // 视图引擎专属初始化（仅前台 boot）
    // -----------------------------------------------------------------------

    /**
     * 初始化视图引擎
     *
     * @return void
     */
    private function setupViewEngine()
    {
        $activeTheme = Container::getInstance()->make(SiteThemePolicy::class)->effectiveTheme();
        // 前台覆盖 site.theme_url 为 SiteThemePolicy 降级后的值（修复未授权 + 商业主题
        // 场景下 site.theme_url 指向商业目录但实际渲染回退 default 的路径错位）
        $themeUrl = ROOT_URL . 'theme/' . $activeTheme . '/';
        Config::set('site.theme_url', $themeUrl);

        $engine = new DouView();
        $engine->template_dir = ROOT_PATH . 'theme/' . $activeTheme;
        $engine->compile_dir = STORAGE_PATH . 'cache/template/front';
        $engine->leftDelimiter = '{';
        $engine->rightDelimiter = '}';
        $engine->setEscapeHtml(true);

        if (!file_exists($engine->compile_dir)) {
            mkdir($engine->compile_dir, 0777, true);
        }

        $engine->registerPrefilter(array(FrontPrefilter::class, 'apply'));

        Container::getInstance()->instance(DouView::class, $engine);
        // 同时按接口注册，供 ViewResponse / view() / BaseController::view() 面向接口解耦
        Container::getInstance()->instance(TemplateRendererInterface::class, $engine);
    }

    /**
     * 向视图引擎分配通用模板变量
     *
     * @return void
     */
    private function assignCommonViewVars()
    {
        $engine = app(DouView::class);
        $langMenu = language()->getLangMenu(locale()->pack());

        $staticUserToken = (string) csrf()->ensure('static_user');
        $engine->assign('lang', lang_all());
        $engine->assign('token', $staticUserToken);
        $engine->assign('csrf_token', $staticUserToken);
        $engine->assign('honeypot_ts', Honeypot::issueTimestamp());
        $engine->assign('keyword', '');
        $engine->assign('theme_path', Config::get('site.theme_url', ''));
        $site = Config::get('site', array());
        if (isset($site['qq']) && is_array($site['qq'])) {
            foreach ($site['qq'] as $k => $qqRow) {
                if (is_array($qqRow) && !array_key_exists('nickname', $qqRow)) {
                    $site['qq'][$k]['nickname'] = '';
                }
            }
        }
        $engine->assign('site', $site);
        $engine->assign('js_route_config_json', JsRouteExporter::toJsonScript(JsRouteExporter::buildFrontConfig()));
        $engine->assign('js_routes_script_url', route('routes_js', array(), array('query' => array('v' => JsRouteExporter::manifestUrlVersion('Front')))));
        $frontLangPack = LangController::currentPack();
        $engine->assign('js_lang_script_url', route('lang_js', array(), array('query' => array('v' => JsLangExporter::manifestUrlVersion('front', $frontLangPack)))));
        $engine->assign('about', Page::about());
        $engine->assign('param', ViewVars::param(Config::get('param', array())));
        $engine->assign('features', ViewVars::features(Config::get('features', array())));
        $engine->assign('link_list', class_exists(Link::class) ? Link::linkList() : array());
        $engine->assign('lang_menu', $langMenu);
        $engine->assign('generator', 'DouPHP v1.9');
        $engine->assign('authorized', Config::get('app.licensed', false));
        $engine->assign('url', (new Fix())->buildLegacyUrlMap((string) request()->routeModule()));
        // 全站 head 自定义代码默认值；详情页等控制器可再 assign 覆盖（如叠加 schema）
        $engine->assign('code_head', Config::get('site.code_head', ''));
        // common_header 购物车角标：未安装 order 模块或未登录为 0；与 order_cart 行数量汇总一致（角标用件数）
        $cartTotal = 0;
        if (Config::get('features.order', false)) {
            $auth = auth('front');
            if (is_object($auth)
                && method_exists($auth, 'check') && method_exists($auth, 'id')
                && $auth->check()) {
                $userId = (int) $auth->id();
                if ($userId > 0) {
                    $cartTotal = (int) DB::table('order_cart')->where('user_id', $userId)->sum('item_number');
                }
            }
        }
        $engine->assign('cart_total', $cartTotal);
        // 头部模板用 $index.cur 标记首页；非首页未由控制器赋值时避免 Undefined array key
        $engine->assign('index', array('cur' => false));

        $fragment = array();
        $assignedFragment = $engine->getAssigned('fragment');
        if (is_array($assignedFragment)) {
            $fragment = $assignedFragment;
        }
        if (!isset($fragment['about']) || !is_array($fragment['about'])) {
            $fragment['about'] = array();
        }
        if (!array_key_exists('image', $fragment['about'])) {
            $fragment['about']['image'] = '';
        }
        $engine->assign('fragment', $fragment);
    }

    // -----------------------------------------------------------------------
    // 站点关闭检测
    // -----------------------------------------------------------------------

    /**
     * 站点关闭检测
     *
     * @return void
     */
    private function checkSiteClosed()
    {
        if (Config::get('site.site_closed', false)) {
            header('Content-type: text/html; charset=' . DOU_CHARSET);
            echo '<meta http-equiv="Content-Type" content="text/html; charset=' . DOU_CHARSET . '">';
            echo '<div style="margin:200px;text-align:center;font-size:14px"><p>' . lang('site_closed') . '</p></div>';
            exit();
        }
    }
}
