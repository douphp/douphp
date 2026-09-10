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

namespace Dou\Core\Web\Template\Expression\Ast;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 表达式 AST 节点（标记联合）。各字段按 {@see ExprNodeType} 取用：
 *
 *   VAR_PATH  : value=变量名；segments=访问段（'.a' / '.$a' / '[..]' / '@prop'）
 *   NUMBER    : value=数字原文
 *   STRING    : value=带引号原文；meta['quote']='"'|'\''
 *   BAREWORD  : value=裸词原文
 *   MATH      : children=操作数节点；meta['ops']=操作符字符序列（children 数 - 1）
 *   MODIFIED  : children[0]=被包裹节点；meta['modifiers']=[ ['name'=>, 'args'=>ExprNode[]] ]
 *   RAW       : value=成品 PHP 片段
 */
class ExprNode
{
    /** @var string 节点类型（{@see ExprNodeType} 常量） */
    public $type;
    /** @var string 标量载荷（变量名 / 数字 / 字符串 / 裸词 / 成品 PHP） */
    public $value = '';
    /** @var string[] 变量访问段序列（仅 VAR_PATH） */
    public $segments = array();
    /** @var ExprNode[] 子节点（MATH 操作数 / MODIFIED 被包裹节点） */
    public $children = array();
    /** @var array 附加载荷（quote / ops / modifiers） */
    public $meta = array();

    /**
     * @param string $type 节点类型
     * @param string $value 标量载荷
     */
    public function __construct($type, $value = '')
    {
        $this->type = $type;
        $this->value = $value;
    }
}
