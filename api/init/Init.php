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

namespace Dou\Api\Init;

use Dou\Api\Facade\Auth;
use Dou\Api\Service\Miniprogram\MiniprogramCatalogQuery;
use Dou\Core\Bootstrap\SiteBootstrap;
use Dou\Core\Bootstrap\SystemBootstrap;
use Dou\Core\Contract\LanguageContract;
use Dou\Core\Contract\PluginServiceContract;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Auth\GuestGuard;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Container\Container;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Foundation\Provider\ProviderRegistry;
use Dou\Core\Init\InitTrait;
use Dou\Core\Service\Nav\MiniprogramNavigationBuilder;
use Dou\Core\Service\Pricing\PricingService;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\ApiResponse;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * API 初始化类（独立链路，不复用 front/init/Init.php）
 *
 * 封装 API 启动逻辑，通过 boot() 实例方法调用。
 * 业务侧通过 helper / 门面（DB::、auth('api')、Config::、locale() 等）就近读取依赖。
 */
class Init
{
    use InitTrait;

    /**
     * @var callable|null
     */
    private $userFactory;

    /**
     * 构造函数
     *
     * @param callable|null $userFactory function(): object
     */
    public function __construct($userFactory = null)
    {
        $this->userFactory = $userFactory;
    }

    /**
     * API 启动入口
     *
     * @return void
     */
    public function boot()
    {
        $this->bootCommon();
        $this->bootCore();
        $this->loadLanguageAndModules();
        $this->checkSiteClosed();

        ob_start();
    }

    // -----------------------------------------------------------------------
    // 公共初始化步骤
    // -----------------------------------------------------------------------

    /**
     * 执行基础初始化步骤
     *
     * @return void
     */
    private function bootCommon()
    {
        $this->startSession();
        $this->setErrorReporting();
        $this->setTimezone();
        $this->defineApiConstants();

        $this->computeRootUrl();
        $this->loadCustomFile();
    }

    /**
     * 定义 API 专用常量
     *
     * @return void
     */
    private function defineApiConstants()
    {
        if (!defined('IS_API')) {
            define('IS_API', true);
        }
        if (!defined('IS_MINIPROGRAM')) {
            define('IS_MINIPROGRAM', true);
        }
        if (!defined('API_DIR')) {
            define('API_DIR', 'api');
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

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', Config::get('site.root_url', '/'));
        }
        if (!defined('HOME_URL')) {
            define('HOME_URL', Config::get('site.home_url', ROOT_URL));
        }
        if (!defined('API_URL')) {
            define('API_URL', ROOT_URL . API_DIR . '/');
        }

        $this->defineShellConstants('mini', 'miniprogram');

        $defaultLanguage = Config::get('site.language', '') ?: 'zh_cn';
        $boot = SystemBootstrap::loadCore(array(
            'isAdmin' => false,
            'langPack' => $defaultLanguage,
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

        // 会员衍生模块依赖检查（清单由 Module::userDerivedFeatures() 提供；阻断由 Router 闸住，本处仅记录警告）。
        $this->warnFeaturesRequireUser();

        // features.language 此刻已生效，重新解析语言契约：开启且模块在 → 真实现；否则保留 Null
        // bootCore 早期已 instance() 锁了 Null 占位（且按容器「instance 覆盖 factory」语义清掉了原 factory），
        // 这里必须重新跑 ProviderRegistry 注册回 factory，才能让 make() 基于最新 features 拉到真实现。
        // PluginServiceContract 同理。
        ProviderRegistry::registerAll($container);
        $container->instance(LanguageContract::class, $container->make(LanguageContract::class));

        // 通用 Domain 服务
        $container->instance(PluginServiceContract::class, $container->make(PluginServiceContract::class));
        $container->instance(MiniprogramNavigationBuilder::class, new MiniprogramNavigationBuilder());

        // API 端 ViewModel 注册容器（无终止提示页；message() 解析到 NullMessageResponder）
        $pricing = $container->has(PricingService::class)
            ? $container->make(PricingService::class)
            : new PricingService();
        $container->instance(MiniprogramCatalogQuery::class, new MiniprogramCatalogQuery($pricing));
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

        header('Content-Type: application/json; charset=' . DOU_CHARSET);

        $this->loadLanguageFiles();

        // 授权检测：结果落入 Config::set('app.licensed', bool)
        Config::set('app.licensed', false);
        if (file_exists($cdkeyFile = STORAGE_PATH . 'state/cdkey.php')) {
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
        lang_set('copyright', lang_has('copyright')
            ? preg_replace('/d%/Ums', Config::get('site.site_name', ''), lang('copyright'))
            : '');
        lang_set('powered_by', lang_has('powered_by')
            ? preg_replace('/d%/Ums', $powerText, strip_tags(lang('powered_by')))
            : '');

        if (Config::get('app.licensed', false)) {
            lang_set('powered_by', '');
        }

        $container = Container::getInstance();

        // API auth guard：先以 GuestGuard 兜底，user 模块就位时再覆盖为真实 Auth。
        // 登录态由 UserAuthMiddleware::handle() 经 auth('api')->resolveUserContext() 完成
        // Authorization: Bearer <token> 校验后再调 hydrate 注入。
        $this->registerAuthGuard('api', new GuestGuard());

        // 会员模块（通过 Module::make 解析，触发容器构造注入子服务；测试可注入 userFactory）
        if (Config::get('features.user', false)) {
            $userService = is_callable($this->userFactory)
                ? call_user_func($this->userFactory)
                : Module::make('user');
            if ($userService !== null) {
                $container->instance(\Dou\Core\Service\User\UserService::class, $userService);
            }

            if (class_exists('Dou\\Api\\Facade\\Auth')) {
                $this->registerAuthGuard('api', new Auth());
            }

            $container->instance(
                \Dou\Front\Service\User\UserCenterNavBuilder::class,
                new \Dou\Front\Service\User\UserCenterNavBuilder()
            );
        }

        // 核心扩展加载文件
        if (file_exists($f = ROOT_PATH . 'include/core.load.php')) {
            require($f);
        }
    }

    // -----------------------------------------------------------------------
    // 站点关闭检测
    // -----------------------------------------------------------------------

    /**
     * 站点关闭检测（JSON 输出）
     *
     * @return void
     */
    private function checkSiteClosed()
    {
        if (Config::get('site.site_closed', false)) {
            $message = lang('site_closed');
            // 站点关闭：业务语义靠近 SERVER_ERROR 家族，HTTP 503 用以兼容运维探活。
            ApiResponse::error(ApiCodes::SERVER_ERROR, $message, array(), 503)->send();
            exit();
        }
    }
}
