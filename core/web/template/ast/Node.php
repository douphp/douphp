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
 * AST 节点：标记联合（tagged union）形态，以 {@see NodeType} 区分。
 *
 * 控制结构（if/foreach/list/category/strip）通过 children / branches / elseChildren 表达嵌套；
 * 叶子节点（echo/url/include/...）把原始片段存入 data，由 {@see \Dou\Core\Web\Template\CodeGenerator} 在 emit 期 lowering。
 */
class Node
{
    /** @var string 节点类型（{@see NodeType} 常量） */
    public $type;
    /** @var int 源模板行号（错误定位） */
    public $line = 1;
    /** @var Node[] 子节点（块体；DOCUMENT 顶层节点） */
    public $children = array();
    /** @var Node[] else 分支子节点（foreach/list/category 的 *else 段） */
    public $elseChildren = array();
    /** @var bool 是否含 else 段（foreach/list/category） */
    public $hasElse = false;
    /** @var array if 分支：每项 array('keyword','args','line','children') */
    public $branches = array();
    /** @var array 原始片段载荷（按类型存 command/modifier/args/raw/text/which 等） */
    public $data = array();

    /**
     * @param string $type 节点类型
     * @param int $line 行号
     */
    public function __construct($type, $line = 1)
    {
        $this->type = $type;
        $this->line = (int) $line;
    }
}
