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

namespace Dou\Core\Foundation\Extension;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Container\Container;
use Dou\Core\Foundation\Exception\DomainException;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 可选业务模块解析（静态门面）
 *
 * 在 features 开启且实现类仍在磁盘时，通过容器 make 对应 Service；
 * 否则 has() 为 false、make() 返回 null，避免未安装模块时构造注入具体类导致 500。
 *
 * 与后台「模块安装/卸载」{@see \Dou\Admin\Service\Module\ModuleService} 无关，仅负责运行时按需取实例。
 *
 * 键有两种形态：
 * - 单段（短名）：`comment` / `attribute` / `favorites` …，按规律
 *   `Dou\{Layer}\Service\{Studly}\{Studly}Service` 跨 layer 探测；
 *   feature 标志位 = `features.{短名}`。
 * - 双段（dot 键，推荐用于非默认命名的子能力）：`feature.classBase`，
 *   与 {@see \Dou\Core\Web\Routing\UrlGenerator::url()} 的 `'module.action'` 视觉对齐。
 *   - 第一段 = feature 键（`features.{seg1}` 标志位），同时也是 namespace 段 `Studly(seg1)`。
 *   - 第二段 = 类基名；探测 `{Studly(seg2)}Service` 与 `{Studly(seg2)}` 两候选，
 *     当 seg2 以 `_service` 结尾时只试 `{Studly(seg2)}`，避免拼成 `FavoritesServiceService`。
 *   - 跨 layer 探测顺序与单段一致（core → front → admin → api），首个 class_exists 胜出。
 *
 * 端侧探测顺序按 {@see $layerNamespaces} 声明：core → front → admin → api。
 * 若均未落地文件则回落到首个候选（has/make 判不可用）。
 *
 * 使用约定：
 * <pre>
 * // 单段短名（默认命名规律命中）
 * $comment = Module::make('comment');
 * if ($comment) {
 *     app(\Dou\Core\Web\Template\DouView::class)->assign('comment', $comment->data('product', $id, 10, $page));
 * }
 *
 * // dot 键（feature.classBase，非默认命名如 Reader/Builder/等）
 * $levelOption = Module::make('user.user_level_option_builder');
 * </pre>
 */
class Module
{
    /**
     * 会员衍生模块清单：features.{name} 启用必须以 features.user 启用为前提，
     * 其前端/后端 Service 通常硬注入 user 模块下发的类（如 WalletService、UserStatsService），
     * features.user 关闭时容器构造控制器会直接炸；由 {@see assertUserAvailable()} 在 Router 闸住。
     *
     * @var array
     */
    protected static $requireUser = array('order', 'vip', 'point', 'money', 'withdraw', 'share', 'favorites');

    /**
     * 特殊模块定义（"指向不在 Service\ 规约目录里的 FQCN"等无法走命名规律的系统级特例
     * 可默认写在此处；命名规律能覆盖的情形保持空，子模块按需在运行期 {@see register()}
     * 注入；键可使用短名或 dot 键）。
     *
     * 当前默认 override：
     * - 'plugin.connect_registry' → Dou\Core\Infra\Plugin\Registry\ConnectPluginRegistry
     *   （由 {@see \Dou\Front\Service\User\UserCenterPresenter::buildConnectPluginList()} 使用；
     *   plugin pack 的 Infra 类不在 Service\ 子树下，命名规约探不到，必须 override）
     * - 'plugin.registry_aggregator' → Dou\Core\Infra\Plugin\Registry\PluginRegistryAggregator
     *   （由 {@see \Dou\Core\Service\Order\OrderReconciliation}、收银台等按需解析支付插件注册表）
     *
     * 适用 register() 的情形：「锁 layer」「自定义 feature 键名」「指向不在规约目录里的
     * FQCN」；运行期注入示例：
     * <pre>
     * Module::register('plugin.connect_registry', array(
     *     'feature' => 'plugin',
     *     'class' => 'Dou\\Core\\Infra\\Plugin\\Registry\\ConnectPluginRegistry',
     * ));
     * </pre>
     *
     * @var array
     */
    protected static $definitions = array(
        'plugin.connect_registry' => array(
            'feature' => 'plugin',
            'class' => 'Dou\\Core\\Infra\\Plugin\\Registry\\ConnectPluginRegistry',
        ),
        'plugin.registry_aggregator' => array(
            'feature' => 'plugin',
            'class' => 'Dou\\Core\\Infra\\Plugin\\Registry\\PluginRegistryAggregator',
        ),
    );

    /**
     * 端侧 layer 与命名空间前缀对应
     *
     * @var array
     */
    protected static $layerNamespaces = array(
        'core' => 'Dou\\Core',
        'front' => 'Dou\\Front',
        'admin' => 'Dou\\Admin',
        'api' => 'Dou\\Api',
    );

    /**
     * 同请求内已解析的模块实例（按 key 缓存；单段短名与 dot 键共存于同一字典）
     *
     * @var array
     */
    protected static $resolved = array();

    /**
     * 注册或覆盖模块定义（安装新模块包或需要钉死探测结果时可调用）
     *
     * @param string $key 模块键（短名如 `comment`，或 dot 键如 `user.user_level_option_builder`）
     * @param array $definition 与默认规律差异：feature、class（均可选，未写则走规律）
     * @return void
     */
    public static function register($key, array $definition)
    {
        static::$definitions[$key] = $definition;
        unset(static::$resolved[$key]);
    }

    /**
     * 模块是否可用：features 已开启且实现类存在
     *
     * @param string $key 模块键
     * @return bool
     */
    public static function has($key)
    {
        $definition = static::getDefinition($key);
        if ($definition === null) {
            return false;
        }

        if (!Config::get('features.' . $definition['feature'], false)) {
            return false;
        }

        return class_exists($definition['class']);
    }

    /**
     * 是否会员衍生模块（强依赖 user 模块）
     *
     * @param string $module 模块短名
     * @return bool
     */
    public static function requiresUser($module)
    {
        return is_string($module) && in_array($module, static::$requireUser, true);
    }

    /**
     * 返回会员衍生模块清单（供 InitTrait::warnFeaturesRequireUser 复用，避免三端 Init 重复字面量）
     *
     * @return array
     */
    public static function userDerivedFeatures()
    {
        return static::$requireUser;
    }

    /**
     * 路由级闸：命中 user 衍生清单且 features.user 关闭时抛 DomainException。
     *
     * 用途：在三端 Resolver 解出 module/cur 之后、产出 DispatchPlan（交 Dispatcher 构造控制器）之前调用，
     * 避免容器构造控制器时反射到 user 模块下发的类（如 WalletService）出现
     * "Cannot resolve parameter" 致命错误；异常由三端入口 catch (DomainException) 统一渲染。
     *
     * @param string $module 当前请求路由模块名
     * @return void
     * @throws DomainException
     */
    public static function assertUserAvailable($module)
    {
        if (!static::requiresUser($module)) {
            return;
        }
        if (Config::get('features.user', false)) {
            return;
        }

        $backUrl = defined('ROOT_URL') ? ROOT_URL : '/';
        throw new DomainException(lang('module_no_install_user'), $backUrl);
    }

    /**
     * 解析模块 Service 实例；不可用时返回 null
     *
     * @param string $key 模块键
     * @return object|null
     */
    public static function make($key)
    {
        if (!static::has($key)) {
            return null;
        }

        if (isset(static::$resolved[$key])) {
            return static::$resolved[$key];
        }

        $definition = static::getDefinition($key);
        $instance = Container::getInstance()->make($definition['class']);
        static::$resolved[$key] = $instance;

        return $instance;
    }

    /**
     * 返回模块实现类 FQCN；未注册时返回 null
     *
     * @param string $key 模块键
     * @return string|null
     */
    public static function className($key)
    {
        $definition = static::getDefinition($key);

        return $definition !== null ? $definition['class'] : null;
    }

    /**
     * 清除本请求内 make 缓存（测试或动态 register 后使用）
     *
     * @param string|null $key 指定模块键；null 表示清空全部
     * @return void
     */
    public static function forgetResolved($key = null)
    {
        if ($key === null) {
            static::$resolved = array();
            return;
        }

        unset(static::$resolved[$key]);
    }

    /**
     * 合并手写项与默认规律，得到完整定义
     *
     * @param string $key 模块键（短名或 dot 键）
     * @return array|null feature、class；无法解析时 null
     */
    protected static function getDefinition($key)
    {
        if (!is_string($key) || $key === '') {
            return null;
        }

        $override = isset(static::$definitions[$key]) && is_array(static::$definitions[$key])
            ? static::$definitions[$key]
            : array();

        if (strpos($key, '.') !== false) {
            return static::resolveDotDefinition($key, $override);
        }

        return static::resolveSingleDefinition($key, $override);
    }

    /**
     * 单段短名解析：`features.{key}` + `Dou\{Layer}\Service\{Studly}\{Studly}Service`
     *
     * @param string $key
     * @param array $override
     * @return array|null
     */
    protected static function resolveSingleDefinition($key, array $override)
    {
        $feature = isset($override['feature']) && $override['feature'] !== ''
            ? $override['feature']
            : $key;

        if (isset($override['class']) && $override['class'] !== '') {
            return array(
                'feature' => $feature,
                'class' => $override['class'],
            );
        }

        $studly = static::toStudly($key);

        $class = static::resolveAutoClass($studly);
        if ($class === null) {
            return null;
        }

        return array(
            'feature' => $feature,
            'class' => $class,
        );
    }

    /**
     * dot 键解析：`feature.classBase`，feature = seg1，
     * namespace 段 = `Studly(seg1)`，类基名探测 `{Studly(seg2)}Service` / `{Studly(seg2)}`。
     *
     * @param string $key
     * @param array $override
     * @return array|null
     */
    protected static function resolveDotDefinition($key, array $override)
    {
        $parts = explode('.', $key, 2);
        $featureName = $parts[0];
        $sub = isset($parts[1]) ? $parts[1] : '';
        if ($featureName === '' || $sub === '') {
            return null;
        }

        $feature = isset($override['feature']) && $override['feature'] !== ''
            ? $override['feature']
            : $featureName;

        if (isset($override['class']) && $override['class'] !== '') {
            return array(
                'feature' => $feature,
                'class' => $override['class'],
            );
        }

        $featureFolder = static::toStudly($featureName);
        $subStudly = static::toStudly($sub);
        $endsWithService = (substr(strtolower($sub), -strlen('_service')) === '_service');
        $candidates = $endsWithService
            ? array($subStudly)
            : array($subStudly . 'Service', $subStudly);

        $fallback = null;
        foreach (array_keys(static::$layerNamespaces) as $layer) {
            $root = static::$layerNamespaces[$layer];
            foreach ($candidates as $basename) {
                $fqcn = $root . '\\Service\\' . $featureFolder . '\\' . $basename;
                if ($fallback === null) {
                    $fallback = $fqcn;
                }
                if (class_exists($fqcn)) {
                    return array(
                        'feature' => $feature,
                        'class' => $fqcn,
                    );
                }
            }
        }

        return array(
            'feature' => $feature,
            'class' => $fallback,
        );
    }

    /**
     * 模块短名段转 Studly（支持下划线分段：`user_level` → `UserLevel`）
     *
     * @param string $name
     * @return string
     */
    protected static function toStudly($name)
    {
        if ($name === '') {
            return '';
        }
        if (strpos($name, '_') === false) {
            return ucfirst(strtolower($name));
        }
        $segments = explode('_', strtolower($name));
        $studly = '';
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            $studly .= ucfirst($segment);
        }
        return $studly;
    }

    /**
     * 按 layer 与 Studly 段生成默认 Service 类名
     *
     * @param string $layer core|front|admin|api
     * @param string $studly 如 Comment
     * @return string|null
     */
    protected static function classForLayer($layer, $studly)
    {
        if (!isset(static::$layerNamespaces[$layer])) {
            return null;
        }

        $root = static::$layerNamespaces[$layer];

        return $root . '\\Service\\' . $studly . '\\' . $studly . 'Service';
    }

    /**
     * 单段短名的跨 layer 自动探测：按 {@see $layerNamespaces} 键顺序生成候选 FQCN，
     * 返回第一个 class_exists 的类名；若均不存在则返回首个候选（core），供 has() 判不可用。
     *
     * @param string $studly 如 Comment、Favorites
     * @return string|null
     */
    protected static function resolveAutoClass($studly)
    {
        $fallback = null;
        foreach (array_keys(static::$layerNamespaces) as $layer) {
            $candidate = static::classForLayer($layer, $studly);
            if ($candidate === null) {
                continue;
            }
            if ($fallback === null) {
                $fallback = $candidate;
            }
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return $fallback;
    }
}
