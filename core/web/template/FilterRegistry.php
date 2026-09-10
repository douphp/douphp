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

namespace Dou\Core\Web\Template;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 修饰器注册表：名称 → callable。运行期由 {@see RenderContext::filter()} 按名分发。
 *
 * 标准修饰器集中在 {@see \Dou\Core\Web\Template\Filter\StandardFilters}。
 */
class FilterRegistry
{
    /** @var callable[] */
    private $filters = array();

    /**
     * 注册一个修饰器。
     *
     * @param string $name 修饰器名（如 'truncate'、'date_format'）
     * @param callable $callable 形如 function ($value, $arg1, ...) { ... }
     * @return void
     */
    public function register($name, $callable)
    {
        $this->filters[(string) $name] = $callable;
    }

    /**
     * 是否已注册该修饰器。
     *
     * @param string $name
     * @return bool
     */
    public function has($name)
    {
        return isset($this->filters[(string) $name]);
    }

    /**
     * 取修饰器 callable。
     *
     * @param string $name
     * @return callable|null
     */
    public function get($name)
    {
        return isset($this->filters[(string) $name]) ? $this->filters[(string) $name] : null;
    }
}
