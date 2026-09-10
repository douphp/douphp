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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 站点路由规则集中读取（只读）
 *
 * 合并 config/route.php 与 route_custom.php，按 site.route_* 选中风格，
 * 供 UrlBuilder、PrettyRouteMatcher、RouteIdValidator 等共用，避免多处重复 include。
 */
class RouteRules
{
    /** @var array|null 选中风格下的规则分组，键为 page / column / simple */
    private static $selectedRuleGroups = null;

    /**
     * 清空进程内缓存（例如单测或热更新配置后可调用）
     *
     * @return void
     */
    public static function clearCache()
    {
        self::$selectedRuleGroups = null;
    }

    /**
     * 返回当前站点选中的规则分组（page / column / simple → rules 数组）
     *
     * @return array
     */
    public static function getSelectedRuleGroups()
    {
        if (self::$selectedRuleGroups !== null) {
            return self::$selectedRuleGroups;
        }

        $root = self::rootPath();
        $file = $root . 'config/route.php';
        if (!file_exists($file)) {
            return self::$selectedRuleGroups = array();
        }

        $default = include $file;
        if (!is_array($default) || empty($default)) {
            return self::$selectedRuleGroups = array();
        }

        $custom = array();
        $customFile = $root . 'config/route_custom.php';
        if (file_exists($customFile)) {
            $loaded = include $customFile;
            if (is_array($loaded)) {
                $custom = $loaded;
            }
        }

        $config = self::mergeRouteConfig($default, $custom);
        $cfg = Config::get('site', array());
        $typeKeys = array(
            'page' => 'route_page',
            'column' => 'route_column',
            'simple' => 'route_simple',
        );

        $result = array();
        foreach ($typeKeys as $type => $cfgKey) {
            if (empty($config[$type])) {
                continue;
            }
            $styles = $config[$type];
            $selected = isset($cfg[$cfgKey]) ? $cfg[$cfgKey] : '';
            if ($selected !== '' && isset($styles[$selected])) {
                $result[$type] = $styles[$selected]['rules'];
            } else {
                $first = reset($styles);
                $result[$type] = $first['rules'];
            }
        }

        return self::$selectedRuleGroups = $result;
    }

    /**
     * 当前选中 column 风格中「详情」规则的 pattern（第一条无 target 的规则）
     *
     * @return string
     */
    public static function getColumnDetailPattern()
    {
        $groups = self::getSelectedRuleGroups();
        $columnRules = isset($groups['column']) ? $groups['column'] : array();
        foreach ($columnRules as $rule) {
            if (!isset($rule['target'])) {
                return isset($rule['pattern']) ? (string) $rule['pattern'] : '';
            }
        }
        return '';
    }

    /**
     * 栏目详情 pattern 是否包含 {category_slug} 占位符
     *
     * 与 UrlBuilder 中 hasPlaceholder 判定一致：pattern 出现 `{category_slug` 即视为使用分类别名段
     * （含可选段 `[/{category_slug}]`）。业务层仍用 category_id 等规则约束“缺省分类段时谁可访问”。
     *
     * @return bool
     */
    public static function columnDetailPatternUsesCategorySlug()
    {
        $pattern = self::getColumnDetailPattern();
        if ($pattern === '') {
            return false;
        }

        return strpos($pattern, '{category_slug') !== false;
    }

    /**
     * 合并默认路由配置与自定义覆盖（同 style_key 整体替换，自定义优先）
     *
     * @param array $default
     * @param array $custom
     * @return array
     */
    private static function mergeRouteConfig(array $default, array $custom)
    {
        if (empty($custom)) {
            return $default;
        }

        $merged = $default;
        foreach ($custom as $type => $customStyles) {
            if (!is_array($customStyles)) {
                continue;
            }
            if (!isset($merged[$type])) {
                $merged[$type] = array();
            }
            foreach ($customStyles as $styleKey => $styleConfig) {
                $merged[$type][$styleKey] = $styleConfig;
            }
        }

        return $merged;
    }

    /**
     * 站点根路径（末尾带目录分隔符）
     *
     * @return string
     */
    private static function rootPath()
    {
        if (defined('ROOT_PATH')) {
            $root = ROOT_PATH;
            $len = strlen($root);
            if ($len > 0 && ($root[$len - 1] === '/' || $root[$len - 1] === '\\')) {
                return $root;
            }
            return $root . DIRECTORY_SEPARATOR;
        }

        $dir = __DIR__;
        for ($i = 0; $i < 3; $i++) {
            $dir = dirname($dir);
        }

        return $dir . DIRECTORY_SEPARATOR;
    }
}
