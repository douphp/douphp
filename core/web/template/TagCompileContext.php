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

use Dou\Core\Web\Template\Ast\Node;
use Dou\Core\Web\Template\Expression\ExpressionCompiler;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 标签编译上下文：在一次 {@see CodeGenerator::generate()} 期间共享。
 *
 * 承载编译期状态（全局转义 / 定界符 / {strip} 深度 / foreach 自动命名序号 /
 * 标签后追加换行 / 当前资源名）、表达式门面，以及把片段写回编排器、递归 emit
 * 块体子节点、设置错误定位行号的转发入口。
 */
class TagCompileContext
{
    /** @var bool 全局自动 HTML 转义 */
    public $escapeHtml;
    /** @var string 左定界符 */
    public $leftDelimiter;
    /** @var string 右定界符 */
    public $rightDelimiter;
    /** @var string|null 当前资源名（错误定位） */
    public $currentFile = null;
    /** @var string 标签输出后追加的换行（{strip} 内为空） */
    public $additionalNewline = "\n";
    /** @var int {strip} 嵌套深度 */
    public $stripDepth = 0;
    /** @var int 匿名 {foreach} 自动命名序号 */
    public $foreachSeq = 0;

    /** @var ExpressionCompiler 表达式门面 */
    public $expr;

    /** @var CodeGenerator 编排器（片段输出 + 子节点递归） */
    private $generator;

    /**
     * @param CodeGenerator $generator 编排器
     * @param ExpressionCompiler $expr 表达式门面
     * @param bool $escapeHtml 全局自动 HTML 转义
     * @param string $leftDelimiter 左定界符
     * @param string $rightDelimiter 右定界符
     */
    public function __construct(CodeGenerator $generator, ExpressionCompiler $expr, $escapeHtml, $leftDelimiter, $rightDelimiter)
    {
        $this->generator = $generator;
        $this->expr = $expr;
        $this->escapeHtml = (bool) $escapeHtml;
        $this->leftDelimiter = $leftDelimiter;
        $this->rightDelimiter = $rightDelimiter;
    }

    /**
     * 追加一段编译片段到指令序列。
     *
     * @param string $php
     * @return void
     */
    public function emit($php)
    {
        $this->generator->pushTag($php);
    }

    /**
     * 顺序 emit 块体子节点序列。
     *
     * @param Node[] $nodes
     * @return void
     */
    public function emitNodes(array $nodes)
    {
        $this->generator->emitNodes($nodes);
    }

    /**
     * 设置当前错误定位行号（资源名固定为本次编译目标）。
     *
     * @param int $line
     * @return void
     */
    public function setLine($line)
    {
        $this->expr->setLocation($this->currentFile, $line);
    }
}
