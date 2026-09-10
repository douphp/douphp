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

use Dou\Core\Web\Template\Contract\TagCompilerInterface;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 标签编译器注册表：{@see \Dou\Core\Web\Template\Ast\NodeType} → {@see TagCompilerInterface}。
 *
 * {@see CodeGenerator} 遍历 AST 时按节点类型分发到对应编译器；新增 DouPHP 定制标签
 * 只需 register() 一个 TagCompiler，**不必**改 CodeGenerator（对齐 {@see FilterRegistry} 模式
 * 与开发计划 §2.5 扩展约定）。
 */
class TagCompilerRegistry
{
    /** @var TagCompilerInterface[] 节点类型 → 编译器 */
    private $compilers = array();

    /**
     * 注册一个标签编译器。
     *
     * @param string $nodeType {@see \Dou\Core\Web\Template\Ast\NodeType} 常量
     * @param TagCompilerInterface $compiler
     * @return void
     */
    public function register($nodeType, TagCompilerInterface $compiler)
    {
        $this->compilers[(string) $nodeType] = $compiler;
    }

    /**
     * 是否已注册该节点类型。
     *
     * @param string $nodeType
     * @return bool
     */
    public function has($nodeType)
    {
        return isset($this->compilers[(string) $nodeType]);
    }

    /**
     * 取节点类型对应的编译器。
     *
     * @param string $nodeType
     * @return TagCompilerInterface|null
     */
    public function get($nodeType)
    {
        return isset($this->compilers[(string) $nodeType]) ? $this->compilers[(string) $nodeType] : null;
    }
}
