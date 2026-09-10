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

namespace Dou\Core\Web\Template\Contract;

use Dou\Core\Web\Template\Ast\Node;
use Dou\Core\Web\Template\TagCompileContext;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 标签编译器契约：把单个 AST {@see Node} lowering 为 PHP，并经
 * {@see TagCompileContext} 把片段写回编排器（含块体子节点递归）。
 *
 * 约束（见开发计划 preg 治理边界）：实现内**禁止**用 preg_ 解析表达式 /
 * 修饰器 / nofilter / 三元；这些一律走表达式层
 * {@see \Dou\Core\Web\Template\Expression\ExpressionCompiler} 的零 preg 前端。
 */
interface TagCompilerInterface
{
    /**
     * 编译单个节点。
     *
     * @param Node $node 目标节点
     * @param TagCompileContext $ctx 编译上下文（状态 + 片段输出 + 子节点递归）
     * @return void
     */
    public function compile(Node $node, TagCompileContext $ctx);
}
