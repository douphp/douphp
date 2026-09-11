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
 * 输入源：config/module.php、config/route.php 风格规则、系统内置端点表、
 * front|admin|api/route/*.php 声明式路由文件。
 *
 * 产出：RouteEntry[]，顺序与 PrettyRouteMatcher / BackendDeclaredMatcher 入站匹配顺序一致。
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
     * 构建完整 manifest 条目列表，顺序：home → 系统内置 declared → 声明文件 declared。
     *
     * 前台 declared 条目统一经 {@see withPaginationSegment} 补齐分页段；
     * page / column / simple meta 模板不入清单（仅供出站，见 {@see buildRuleGroups}）。
     *
     * @return RouteEntry[]
     */
    public function build()
    {
        $entries = array();

        $entries[] = $this->buildHome();
        foreach ($this->buildSystemDeclared() as $e) {
            $entries[] = $this->withPaginationSegment($e);
        }
        foreach ($this->buildDeclared() as $e) {
            $entries[] = $this->withPaginationSegment($e);
        }

        return $entries;
    }

    /**
     * 前台 declared 条目补齐分页段 `[/o{page:\d*}]`。
     *
     * 已含 `{page` 占位符（风格规则条目）的不重复追加；admin / api 条目分页走查询串，不处理。
     *
     * @param RouteEntry $entry
     * @return RouteEntry 补齐分页段的新条目；不适用时原样返回
     */
    private function withPaginationSegment(RouteEntry $entry)
    {
        if ($entry->route_type !== 'declared' || $entry->endNamespace() !== 'Front') {
            return $entry;
        }

        $pattern = (string) $entry->pattern;
        if ($pattern === '' || strpos($pattern, '{page') !== false) {
            return $entry;
        }

        $fields = $entry->toArray();
        $fields['pattern'] = $pattern . '[/o{page:\d*}]';

        return new RouteEntry($fields);
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
     * 系统内置端点声明（前台，逐 action 一条，route_type='declared'）。
     *
     * 含 llms.txt / sitemap.xml / captcha / search / index / plugin 等端点；
     * plugin 的 finish / notify 为支付异步回调，豁免 csrf。
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
     * 声明式条目（加载 front / admin / api 三端 route 文件夹）。
     *
     * 每个 route 文件返回 RouteEntry[] 或字段数组列表，统一规范化为 RouteEntry；
     * 文件夹缺失时跳过。
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
     * 在独立方法作用域内 include 路由声明文件，避免 include 文件中的变量污染调用方作用域。
     *
     * @param string $file 绝对路径
     * @return mixed include 文件的 return 值
     */
    private function includeRouteFile($file)
    {
        return include $file;
    }

    /**
     * 推断各端 route 文件夹绝对路径（末尾带 'route/'，推断失败返回 null）。
     *
     * 优先用入口常量（FRONT_PATH / API_PATH）；admin 端用 ROOT_PATH + ADMIN_DIR；
     * 均缺失时回退到 ROOT_PATH + 端名。
     *
     * @param string|null $constName
     * @param string $endLower 'front' | 'admin' | 'api'
     * @return string|null
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
