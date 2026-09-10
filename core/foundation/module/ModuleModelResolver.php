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

use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 模块名 → Model FQCN 解析器（slim）。
 *
 * 把模块名（'article' / 'product' / 自定义）拼成 `<base>\<Studly>\<Studly>` 并 class_exists 探测，
 * 命中返回 FQCN，未命中（卸载模块 / 自定义模块）返回 null —— 由调用方回落到 `DB::table` 路径。
 *
 * 各端 Init 接线时按本端模型命名空间注入候选 base（默认前台优先、后台兜底），
 * 使三端共用的 Reader 能据此把「schema 驱动委托」落到正确的 Model 上。
 */
class ModuleModelResolver
{
    /** @var array<int, string> 候选模型基命名空间（按序探测，先命中先用） */
    private $baseNamespaces;

    /** @var array<string, string|null> module => FQCN|null 解析缓存 */
    private $cache = array();

    /**
     * @param array<int, string> $baseNamespaces 形如 array('Dou\\Front\\Model', 'Dou\\Admin\\Model')
     */
    public function __construct(array $baseNamespaces = array())
    {
        if (empty($baseNamespaces)) {
            $baseNamespaces = array('Dou\\Front\\Model', 'Dou\\Admin\\Model');
        }
        $this->baseNamespaces = $baseNamespaces;
    }

    /**
     * 解析模块对应 Model FQCN；未命中返回 null。
     *
     * @param string $module
     * @return string|null
     */
    public function modelClassFor($module)
    {
        $module = (string) $module;
        if ($module === '' || preg_match('/^[a-zA-Z0-9_]+$/', $module) !== 1) {
            return null;
        }
        if (array_key_exists($module, $this->cache)) {
            return $this->cache[$module];
        }

        $studly = Str::studly($module);
        foreach ($this->baseNamespaces as $base) {
            $fqcn = rtrim($base, '\\') . '\\' . $studly . '\\' . $studly;
            if (class_exists($fqcn)) {
                $this->cache[$module] = $fqcn;

                return $fqcn;
            }
        }

        $this->cache[$module] = null;

        return null;
    }

    /**
     * 解析模块对应分类 Model FQCN（`<base>\<Studly>\<Studly>Category`）；未命中返回 null。
     *
     * @param string $module 模块名（不含 `_category` 后缀，如 'article'）
     * @return string|null
     */
    public function categoryModelClassFor($module)
    {
        $module = (string) $module;
        if ($module === '' || preg_match('/^[a-zA-Z0-9_]+$/', $module) !== 1) {
            return null;
        }
        $cacheKey = $module . '_category';
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $studly = Str::studly($module);
        foreach ($this->baseNamespaces as $base) {
            $fqcn = rtrim($base, '\\') . '\\' . $studly . '\\' . $studly . 'Category';
            if (class_exists($fqcn)) {
                $this->cache[$cacheKey] = $fqcn;

                return $fqcn;
            }
        }

        $this->cache[$cacheKey] = null;

        return null;
    }
}
