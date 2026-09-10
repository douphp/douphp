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

namespace Dou\Core\Web\Routing;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Support\Naming;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 路由清单批量生成器
 *
 * 输入源：
 *   - config/module.php 模块注册表（column_module / single_module / link_user_center / ...）
 *   - config/route.php 选中的 page / column / simple 风格规则（经 RouteRules 合并 route_custom）
 *   - 系统内置端点明文表（见 {@see buildSystemDeclared}：llms.txt / sitemap.xml / captcha / search /
 *     plugin / index）
 *   - admin|front|api/route/*.php 声明式路由文件夹
 *
 * 产出：RouteEntry[]，迭代顺序与 PrettyRouteMatcher 入站匹配先后一致。
 *
 * 字段契约：见 docs/adr/2026-06-14-route-manifest-contract.md §2。
 *
 * 三端覆盖：三端入站匹配全部走 declared 条目（系统内置 + 声明文件展开）：
 *   - 前台：由 {@see \Dou\Front\Foundation\Routing\PrettyRouteMatcher} 顺序匹配；
 *   - 后台 / 接口：由 {@see BackendDeclaredMatcher} 顺序匹配。
 */
class RouteManifestBuilder
{
    /** @var ModuleRegistry */
    private $registry;

    public function __construct()
    {
        $this->registry = new ModuleRegistry();
    }

    /**
     * 构建完整 manifest 条目列表（按匹配器迭代顺序）。
     *
     * 顺序约定（与 PrettyRouteMatcher 匹配顺序一致）：
     *   1. home（首页直达，FrontResolver 直返，不入匹配器迭代）
     *   2. **system declared**（llms.txt / sitemap.xml / captcha / search / plugin 等内置端点，
     *      route_type='declared'，按字面段优先匹配，先于 file-based 声明）
     *   3. **file-based declared**（front/admin/api/route 声明式条目）
     *
     * 入站匹配清单不含 page / column / simple meta 模板：栏目 / 单表 / 单页的入站匹配已由
     * 声明文件经 {@see StyleRuleExpander}（Route::column / simple / page）展开为具体 declared
     * 条目承载。meta 模板仅作为出站生成视图保留，见 {@see buildRuleGroups}（供 UrlBuilder 读取）。
     *
     * @return RouteEntry[]
     */
    public function build()
    {
        $entries = array();

        $entries[] = $this->buildHome();
        foreach ($this->buildSystemDeclared() as $e) {
            $entries[] = $e;
        }
        foreach ($this->buildDeclared() as $e) {
            $entries[] = $e;
        }

        return $entries;
    }

    /**
     * 仅构建匹配器使用的 meta 规则视图（page / column / simple 分组）。
     *
     * 供 UrlBuilder 按 page/column/simple 分组取规则；不返回家族条目 / 具名条目。
     *
     * @return array {page?: array, column?: array, simple?: array}
     */
    public function buildRuleGroups()
    {
        $groups = array();
        foreach ($this->buildFromRouteRules() as $entry) {
            $type = $entry->route_type;
            if (!in_array($type, array('page', 'column', 'simple'), true)) {
                continue;
            }
            if (!isset($groups[$type])) {
                $groups[$type] = array();
            }
            $groups[$type][] = $entry->toRuleArray();
        }
        return $groups;
    }

    /**
     * 首页条目（前台 '/'）。
     *
     * @return RouteEntry
     */
    private function buildHome()
    {
        return new RouteEntry(array(
            'name' => 'home',
            'route_type' => 'home',
            'pattern' => '',
            'module' => 'index',
            'controller' => '\\Dou\\Front\\Controller\\Index\\IndexController',
            'action' => 'index',
            'is_short_url_aware' => false,
            'is_family' => false,
            'source' => 'home',
        ));
    }

    /**
     * 系统内置端点声明（前台命名空间，明文表，逐 action 一条）。
     *
     * 包含 llms.txt / sitemap.xml 等字面端点，以及 captcha / search / plugin 等需要按
     * action 显式覆盖的系统控制器。所有条目 route_type='declared'、controller 明文填写，
     * 由 {@see PrettyRouteMatcher} 当作普通 declared 入站规则匹配，同时计入
     * {@see route-coverage-scan.php} 覆盖率，使「fully undeclared 控制器数 = 0」成为硬门禁。
     *
     * 注：home（首页空串）由 {@see buildHome} 独立承载，仍走 FrontResolver 短路；
     * category 没有专属前台 Controller，不在此声明（`?route=category` 自然 404）。
     *
     * @return RouteEntry[]
     */
    private function buildSystemDeclared()
    {
        $entries = array();

        // 字面端点：llms.txt / sitemap.xml（路径含 `.`，由 PrettyUrlCompiler 自动转义）
        $entries[] = $this->systemEntry('llms.txt', 'llms.txt', 'llms', '\\Dou\\Front\\Controller\\Llms\\LlmsController', 'index');
        $entries[] = $this->systemEntry('sitemap.xml', 'sitemap.xml', 'sitemap', '\\Dou\\Front\\Controller\\Sitemap\\SitemapController', 'index');

        // captcha：index / verification
        $entries[] = $this->systemEntry('captcha', 'captcha', 'captcha', '\\Dou\\Front\\Controller\\Captcha\\CaptchaController', 'index');
        $entries[] = $this->systemEntry('captcha.verification', 'captcha/verification', 'captcha', '\\Dou\\Front\\Controller\\Captcha\\CaptchaController', 'verification');

        // search：index
        $entries[] = $this->systemEntry('search', 'search', 'search', '\\Dou\\Front\\Controller\\Search\\SearchController', 'index');

        // index（前台模块直达）：index
        $entries[] = $this->systemEntry('index.endpoint', 'index', 'index', '\\Dou\\Front\\Controller\\Index\\IndexController', 'index');

        // plugin/{plugin_id}[/{action}]：5 个 action 逐条声明。
        // finish / notify 为支付网关外部异步回调（无 session / 无令牌），声明式豁免 csrf。
        $pluginParams = array('plugin_id' => '[a-zA-Z][a-zA-Z0-9_-]*');
        $csrfExempt = array('without_middleware' => array('csrf'));
        $entries[] = $this->systemEntry('plugin.start', 'plugin/{plugin_id}/start', 'plugin', '\\Dou\\Front\\Controller\\Plugin\\PluginController', 'start', $pluginParams);
        $entries[] = $this->systemEntry('plugin.finish', 'plugin/{plugin_id}/finish', 'plugin', '\\Dou\\Front\\Controller\\Plugin\\PluginController', 'finish', $pluginParams, $csrfExempt);
        $entries[] = $this->systemEntry('plugin.notify', 'plugin/{plugin_id}/notify', 'plugin', '\\Dou\\Front\\Controller\\Plugin\\PluginController', 'notify', $pluginParams, $csrfExempt);
        $entries[] = $this->systemEntry('plugin.status', 'plugin/{plugin_id}/status', 'plugin', '\\Dou\\Front\\Controller\\Plugin\\PluginController', 'status', $pluginParams);
        $entries[] = $this->systemEntry('plugin', 'plugin/{plugin_id}', 'plugin', '\\Dou\\Front\\Controller\\Plugin\\PluginController', 'index', $pluginParams);

        return $entries;
    }

    /**
     * 构造一条系统内置 declared 条目（统一字段默认值）。
     *
     * @param string $name 路由名（点分）
     * @param string $pattern URL pattern
     * @param string $module 模块名
     * @param string $controller 控制器 FQCN
     * @param string $action 动作名
     * @param array $params 占位符正则约束
     * @param array $mw 路由级中间件细化字段（middleware / without_middleware / middleware_params /
     *                  skip_all_middleware），缺省为空（全走默认栈）
     * @return RouteEntry
     */
    private function systemEntry($name, $pattern, $module, $controller, $action, array $params = array(), array $mw = array())
    {
        $fields = array(
            'name' => $name,
            'route_type' => 'declared',
            'pattern' => $pattern,
            'params' => $params,
            'module' => $module,
            'controller' => $controller,
            'action' => $action,
            'is_short_url_aware' => false,
            'is_family' => false,
            'source' => 'system:' . $name,
        );

        return new RouteEntry(array_merge($fields, $mw));
    }

    /**
     * 风格规则条目（page / column / simple，按 RouteRules 顺序）。
     *
     * 保留 meta 模板（{module} 占位符），匹配时由调用方通过 ModuleRegistry 准入校验。
     * 同一规则的多个条目按 RouteRules 顺序入清单。
     *
     * @return RouteEntry[]
     */
    private function buildFromRouteRules()
    {
        $entries = array();
        $groups = RouteRules::getSelectedRuleGroups();

        // 顺序与 PrettyRouteMatcher::loadRoutePatterns 一致：page → column → simple
        $order = array('page', 'column', 'simple');
        foreach ($order as $type) {
            if (empty($groups[$type])) {
                continue;
            }
            foreach ($groups[$type] as $idx => $rule) {
                $entries[] = new RouteEntry(array(
                    'name' => null,
                    'route_type' => $type,
                    'pattern' => isset($rule['pattern']) ? $rule['pattern'] : '',
                    'params' => isset($rule['params']) && is_array($rule['params']) ? $rule['params'] : array(),
                    'target' => isset($rule['target']) ? $rule['target'] : null,
                    'module_fixed' => isset($rule['module_fixed']) ? $rule['module_fixed'] : null,
                    'is_short_url_aware' => ($type === 'column'),
                    'is_family' => false,
                    'source' => 'rules:' . $type . ':' . $idx,
                ));
            }
        }

        return $entries;
    }

    /**
     * 声明式条目（前台 / 后台 / 接口路由文件夹）。
     *
     * 各端 route 文件夹缺失时返回空；front/route/<模块>.php 等声明 /user/<模块>/* 等
     * 具名路由入站映射。每个 route 文件返回 RouteEntry[] 或字段数组列表，由 builder 统一
     * 规范化为 RouteEntry。
     *
     * @return RouteEntry[]
     */
    private function buildDeclared()
    {
        $entries = array();

        $ends = array(
            'Front' => $this->endRoutePath('FRONT_PATH', 'front'),
            'Admin' => $this->endRoutePath(null, 'admin'),
            'Api' => $this->endRoutePath('API_PATH', 'api'),
        );

        foreach ($ends as $ns => $dir) {
            if ($dir === null || !is_dir($dir)) {
                continue;
            }
            foreach ((array) glob($dir . '*.php') as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $collector = new RouteCollector($file);
                Route::useCollector($collector);
                try {
                    $loaded = $this->includeRouteFile($file);
                } catch (\Exception $e) {
                    Route::useCollector(null);
                    throw $e;
                }
                Route::useCollector(null);

                foreach ($collector->flush() as $fluentEntry) {
                    $entries[] = $fluentEntry;
                }

                if (!is_array($loaded)) {
                    continue;
                }
                foreach ($loaded as $row) {
                    if ($row instanceof RouteEntry) {
                        $entries[] = $row;
                        continue;
                    }
                    if (!is_array($row)) {
                        continue;
                    }
                    if (!isset($row['route_type']) || $row['route_type'] === '') {
                        $row['route_type'] = 'declared';
                    }
                    if (!isset($row['source']) || $row['source'] === '') {
                        $name = isset($row['name']) ? $row['name'] : '';
                        $row['source'] = 'declared:' . str_replace('\\', '/', $file) . ':' . $name;
                    }
                    $entries[] = new RouteEntry($row);
                }
            }
        }

        return $entries;
    }

    /**
     * 在隔离方法作用域内 include 路由声明文件，避免被 include 文件中同名变量污染调用方
     * 的 $entries 等局部变量（PHP include 与调用作用域共享变量空间的副作用）。
     *
     * @param string $file 绝对路径
     * @return mixed include 文件的 return 值
     */
    private function includeRouteFile($file)
    {
        return include $file;
    }

    /**
     * 推断各端 route 文件夹绝对路径（不存在时返回 null）。
     *
     * 优先用三端入口已定义的常量（FRONT_PATH / API_PATH）；admin 端根据 ROOT_PATH + ADMIN_DIR
     * 推断（与 AdminResolver 中 init/route.php 加载方式一致）；以上常量缺失时回退到仓库根 + 端名。
     *
     * @param string|null $constName
     * @param string $endLower 'front' | 'admin' | 'api'
     * @return string|null 末尾带 'route/'，若推断失败返回 null
     */
    private function endRoutePath($constName, $endLower)
    {
        if ($constName !== null && defined($constName)) {
            return rtrim(constant($constName), '/\\') . DIRECTORY_SEPARATOR . 'route' . DIRECTORY_SEPARATOR;
        }
        if ($endLower === 'admin' && defined('ROOT_PATH') && defined('ADMIN_DIR')) {
            return rtrim(ROOT_PATH, '/\\') . DIRECTORY_SEPARATOR . trim(ADMIN_DIR, '/') . DIRECTORY_SEPARATOR . 'route' . DIRECTORY_SEPARATOR;
        }
        if (defined('ROOT_PATH')) {
            return rtrim(ROOT_PATH, '/\\') . DIRECTORY_SEPARATOR . $endLower . DIRECTORY_SEPARATOR . 'route' . DIRECTORY_SEPARATOR;
        }
        return null;
    }

    /**
     * 取 link_user_center 列表（供消费方校验声明条目的 module 是否合法）。
     *
     * @return string[]
     */
    public function linkUserCenterModules()
    {
        return (array) Config::get('module.link_user_center', array());
    }

    /**
     * 取栏目模块列表。
     *
     * @return string[]
     */
    public function columnModules()
    {
        return (array) Config::get('module.column_module', array());
    }

    /**
     * 取单表模块列表。
     *
     * @return string[]
     */
    public function singleModules()
    {
        return (array) Config::get('module.single_module', array());
    }

    /**
     * 模块注册表访问接口（供消费方进一步派生数据，如 ModuleRegistry::isColumn 决策）。
     *
     * @return ModuleRegistry
     */
    public function registry()
    {
        return $this->registry;
    }

    /**
     * Naming::baseModule 透出（避免下游消费者再 import）。
     *
     * @param string $module
     * @return string
     */
    public function baseModule($module)
    {
        return Naming::baseModule($module);
    }
}
