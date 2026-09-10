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

namespace Dou\Core\Web\Template\Ast;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * AST 节点类型常量。{@see Parser} 据 Token 产出这些类型的 {@see Node}，{@see \Dou\Core\Web\Template\CodeGenerator} 据此 emit PHP。
 */
class NodeType
{
    /** @var string 文档根（顶层节点容器） */
    const DOCUMENT = 'document';
    /** @var string 原始文本块（HTML） */
    const TEXT = 'text';
    /** @var string 变量/对象/数字输出（{$x|mod}） */
    const ECHO_ = 'echo';
    /** @var string 变量三元（{$a ? x : y}） */
    const TERNARY = 'ternary';
    /** @var string {url ...} */
    const URL = 'url';
    /** @var string {include ...} */
    const INCLUDE_ = 'include';
    /** @var string {assign ...} */
    const ASSIGN = 'assign';
    /** @var string {if}/{elseif}/{else}/{/if} */
    const IF_ = 'if';
    /** @var string {foreach}/{foreachelse}/{/foreach} */
    const FOREACH_ = 'foreach';
    /** @var string {list}/{listelse}/{/list}（Portal 模块列表数据块） */
    const LIST_ = 'list';
    /** @var string {category}/{categoryelse}/{/category}（Portal 分类树数据块） */
    const CATEGORY = 'category';
    /** @var string {strip}/{/strip} */
    const STRIP = 'strip';
    /** @var string {literal}...{/literal} */
    const LITERAL = 'literal';
    /** @var string {* 注释 *} */
    const COMMENT = 'comment';
    /** @var string {php}...{/php}（编译期拒绝） */
    const PHP = 'php';
    /** @var string {ldelim}/{rdelim} */
    const DELIM = 'delim';
    /** @var string {break} */
    const BREAK_ = 'break';
    /** @var string {continue} */
    const CONTINUE_ = 'continue';
}
