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

namespace Dou\Core\Web\Template\Expression;

use Dou\Core\Web\Template\AttrParser;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 表达式编译器门面：对外 API 保持稳定（{@see \Dou\Core\Web\Template\CodeGenerator} 与
 * devtools parity 脚本依赖），内部委托给零 preg 的 Token 驱动前端：
 *
 *   {@see ExprLexer}（扫描）→ {@see ExprParser}（AST）→ {@see ExprEmitter}（emit）
 *   + {@see BuiltinVarResolver}（$smarty.*）+ {@see AttrParser}（标签属性）。
 *
 * 与旧引擎的运行期差异（对模板透明、产物逐字节对齐）：
 *   1) 运行期载体为渲染上下文 $ctx（$ctx->vars / $ctx->loops / $ctx->filter() / $ctx->loopProp()）；
 *   2) $smarty.* 仅编译期识别，不保留 Smarty 运行期命名结构。
 */
class ExpressionCompiler
{
    /** @var string 左定界符 */
    public $leftDelimiter = '{';
    /** @var string 右定界符 */
    public $rightDelimiter = '}';

    /** @var ExprParser 值 / 条件表达式前端 */
    private $parser;
    /** @var AttrParser 标签属性解析器 */
    private $attrParser;

    /**
     * @param string $leftDelimiter
     * @param string $rightDelimiter
     */
    public function __construct($leftDelimiter = '{', $rightDelimiter = '}')
    {
        $this->leftDelimiter = $leftDelimiter;
        $this->rightDelimiter = $rightDelimiter;
        $this->parser = new ExprParser($leftDelimiter, $rightDelimiter);
        $this->attrParser = new AttrParser($this->parser);
    }

    /**
     * 设置错误定位上下文（资源名 + 行号）。
     *
     * @param string $file
     * @param int $line
     * @return void
     */
    public function setLocation($file, $line)
    {
        $this->parser->setLocation($file, $line);
    }

    /**
     * 解析标签属性串为「属性名 => PHP 表达式」映射。
     *
     * @param string $tag_args
     * @return array
     */
    public function parseAttrs($tag_args)
    {
        return $this->attrParser->parse($tag_args);
    }

    /**
     * 解析「变量/对象/字面量 + 修饰器链」为 PHP 表达式。
     *
     * @param string $val
     * @param bool $skip_defaults 跳过默认修饰器（nofilter 时为 true）
     * @return string
     */
    public function parseVarProps($val, $skip_defaults = false)
    {
        return $this->parser->compileValue($val, $skip_defaults);
    }

    /**
     * 编译 {if}/{elseif} 条件表达式为 PHP。
     *
     * @param string $tag_args 条件表达式串
     * @param bool $elseif 是否 elseif
     * @return string 形如 '<?php if (...): ?>'
     */
    public function compileIfCondition($tag_args, $elseif)
    {
        return $this->parser->compileCondition($tag_args, $elseif);
    }

    /**
     * 编译变量三元为 PHP 内层表达式（输出策略留给调用方）。
     *
     * @param string $raw
     * @return array array('expr' => string, 'nofilter' => bool)
     */
    public function compileTernary($raw)
    {
        return $this->parser->compileTernary($raw);
    }

    /**
     * 是否含 nofilter 标记。
     *
     * @param string $s
     * @return bool
     */
    public function hasNofilterFlag($s)
    {
        return $this->parser->hasNofilterFlag($s);
    }

    /**
     * 修饰器链是否含 HTML 转义。
     *
     * @param string $modifier
     * @return bool
     */
    public function hasHtmlEscapeModifier($modifier)
    {
        return $this->parser->hasHtmlEscapeModifier($modifier);
    }

    /**
     * 是否合法标识符（^\w+$）。
     *
     * @param string $s
     * @return bool
     */
    public function isIdentifier($s)
    {
        return $this->parser->isIdentifier($s);
    }

    /**
     * 去引号。
     *
     * @param string $string
     * @return string
     */
    public function dequote($string)
    {
        return $this->parser->dequote($string);
    }

    /**
     * 编译期语法错误：抛出异常中止编译。
     *
     * @param string $error_msg
     * @return void
     * @throws \RuntimeException
     */
    public function syntaxError($error_msg)
    {
        $this->parser->syntaxError($error_msg);
    }
}
