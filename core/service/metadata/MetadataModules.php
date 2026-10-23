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

namespace Dou\Core\Service\Metadata;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 支持扩展字段的内容模块注册表。
 *
 * 清单维护在 config/metadata.php；本类只负责读取清单与生成后台显示名。
 */
class MetadataModules
{
    /** @var array|null 请求内模块清单缓存 */
    private static $modules = null;

    /**
     * @param string $module
     * @return bool
     */
    public static function exists($module)
    {
        return in_array((string) $module, self::modules(), true);
    }

    /**
     * 模块显示名（后台定义页模块选择器）。
     *
     * @return array module => label
     */
    public static function labels()
    {
        $labels = array();
        foreach (self::modules() as $module) {
            $labels[$module] = lang($module, $module);
        }

        return $labels;
    }

    /**
     * @return array
     */
    private static function modules()
    {
        if (self::$modules !== null) {
            return self::$modules;
        }

        self::$modules = array();
        $file = defined('CONFIG_PATH') ? CONFIG_PATH . 'metadata.php' : '';
        if ($file !== '' && is_file($file)) {
            $config = include $file;
            if (is_array($config) && isset($config['modules']) && is_array($config['modules'])) {
                foreach ($config['modules'] as $module) {
                    if (is_string($module) && $module !== '') {
                        self::$modules[] = $module;
                    }
                }
            }
        }

        return self::$modules;
    }
}
