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
 * 表达式 AST 节点类型。{@see \Dou\Core\Web\Template\Expression\ExprParser} 产出，
 * {@see \Dou\Core\Web\Template\Expression\ExprEmitter} 据此 emit $ctx PHP。
 */
class ExprNodeType
{
    /** @var string 变量访问路径（$name.a.b[0][$k]，含 $smarty.* 与 @foreach 属性） */
    const VAR_PATH = 'var_path';
    /** @var string 数字字面量 */
    const NUMBER = 'number';
    /** @var string 字符串字面量（含引号，双引号需展开内嵌变量） */
    const STRING = 'string';
    /** @var string 裸词（true/false/null 等保留词或需转字面量的标识符） */
    const BAREWORD = 'bareword';
    /** @var string 算术链（operands 以 ops 连接） */
    const MATH = 'math';
    /** @var string 修饰器包裹（target 外套 |name:args） */
    const MODIFIED = 'modified';
    /** @var string 预解析片段（$smarty.* 由 BuiltinVarResolver 产出的成品 PHP） */
    const RAW = 'raw';
}
