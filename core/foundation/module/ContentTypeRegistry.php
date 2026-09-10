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

namespace Dou\Core\Foundation\Module;

use Dou\Core\Foundation\Configuration\Config;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 内容类型注册表（极薄）。
 *
 * 遍历已安装内容模块（module.column_module + module.single_module），经
 * {@see ModuleModelResolver} 解析为 Model 类，再按各 Model 的
 * moduleSchema()['export'][channel] 声明筛出某导出通道的类清单。
 *
 * 通道默认值：sitemap / llms 默认 false（需 Model 显式开通）。未声明 moduleSchema 的
 * 模块（如多数系统型 single 模块）自然落入默认值，被排除于 sitemap / llms。
 *
 * 返回 class-string 清单，调用方再用 Cls::moduleSchema() 读元数据、
 * Cls::listForExport() / Cls::categoriesForExport() 取数。
 */
class ContentTypeRegistry
{
    /** @var ModuleModelResolver */
    private $resolver;

    /** @var array<string, bool> 通道未声明时的默认归属 */
    private $defaults = array(
        'sitemap' => false,
        'llms' => false,
    );

    /**
     * @param ModuleModelResolver $resolver 模块名 → Model FQCN
     */
    public function __construct(ModuleModelResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * 已安装内容模块（column + single，按配置顺序）解析出的 Model 类清单。
     *
     * @return array<int, string>
     */
    public function all()
    {
        $modules = array_merge(
            (array) Config::get('module.column_module', array()),
            (array) Config::get('module.single_module', array())
        );

        $classes = array();
        foreach ($modules as $module) {
            $cls = $this->resolver->modelClassFor($module);
            if ($cls !== null) {
                $classes[] = $cls;
            }
        }

        return $classes;
    }

    /**
     * @return array<int, string>
     */
    public function forSitemap()
    {
        return $this->filterByChannel('sitemap');
    }

    /**
     * @return array<int, string>
     */
    public function forLlms()
    {
        return $this->filterByChannel('llms');
    }

    /**
     * 按导出通道筛选 Model 类清单。
     *
     * @param string $channel sitemap / llms
     * @return array<int, string>
     */
    private function filterByChannel($channel)
    {
        $default = isset($this->defaults[$channel]) ? $this->defaults[$channel] : false;

        $out = array();
        foreach ($this->all() as $cls) {
            if (!method_exists($cls, 'moduleSchema')) {
                if ($default) {
                    $out[] = $cls;
                }
                continue;
            }

            $schema = $cls::moduleSchema();
            $value = isset($schema['export'][$channel]) ? $schema['export'][$channel] : $default;
            if (!empty($value)) {
                $out[] = $cls;
            }
        }

        return $out;
    }
}
