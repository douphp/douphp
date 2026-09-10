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

use Dou\Core\Web\Template\Expression\Ast\ExprNode;
use Dou\Core\Web\Template\Expression\Ast\ExprNodeType;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 表达式 emitter：把 {@see ExprNode} lowering 为 $ctx PHP（零 preg）。
 *
 * 运行期载体为 {@see \Dou\Core\Web\Template\RenderContext}：
 *   变量 -> $ctx->vars[...]，foreach 属性 -> $ctx->loopProp()，修饰器 -> $ctx->filter()。
 * 产物与旧 ExpressionCompiler 的 parseVar/parseModifiers/expandQuotedText 逐字节对齐。
 */
class ExprEmitter
{
    /** @var ExprParser */
    private $parser;
    /** @var ExprLexer */
    private $lexer;
    /** @var BuiltinVarResolver */
    private $resolver;

    /**
     * @param ExprParser $parser 宿主（递归 compileValue / syntaxError）
     * @param ExprLexer $lexer 词法扫描器
     * @param BuiltinVarResolver $resolver $smarty.* 解析器
     */
    public function __construct(ExprParser $parser, ExprLexer $lexer, BuiltinVarResolver $resolver)
    {
        $this->parser = $parser;
        $this->lexer = $lexer;
        $this->resolver = $resolver;
    }

    /**
     * emit 一个 AST 节点为 PHP。
     *
     * @param ExprNode $node
     * @return string
     */
    public function emit(ExprNode $node)
    {
        switch ($node->type) {
            case ExprNodeType::RAW:
            case ExprNodeType::NUMBER:
                return $node->value;

            case ExprNodeType::VAR_PATH:
                return $this->emitVar($node->value);

            case ExprNodeType::STRING:
                $quote = isset($node->meta['quote']) ? $node->meta['quote'] : '"';
                if ($quote === '"') {
                    return $this->expandQuotedText($node->value);
                }
                return $node->value;

            case ExprNodeType::BAREWORD:
                $val = $node->value;
                if (in_array($val, $this->parser->getPermittedTokens()) || is_numeric($val)) {
                    return $val;
                }
                return $this->expandQuotedText('"' . strtr($val, array('\\' => '\\\\', '"' => '\\"')) . '"');

            case ExprNodeType::MATH:
                $out = '';
                $ops = isset($node->meta['ops']) ? $node->meta['ops'] : array();
                foreach ($node->children as $k => $child) {
                    if ($k > 0 && isset($ops[$k - 1])) {
                        $out .= $ops[$k - 1];
                    }
                    $out .= $this->emit($child);
                }
                return $out;

            case ExprNodeType::MODIFIED:
                $out = $this->emit($node->children[0]);
                $modifiers = isset($node->meta['modifiers']) ? $node->meta['modifiers'] : array();
                return $this->applyModifiers($out, $modifiers);

            default:
                return $node->value;
        }
    }

    /**
     * 把变量基串 lowering 为 $ctx 访问（移植 parseVar 的非 math 部分；math 已在 AST 上拆出）。
     *
     * @param string $base 变量基串（含前导 $ 或数字）
     * @return string
     */
    public function emitVar($base)
    {
        if (is_numeric(substr($base, 0, 1))) {
            $varRef = $base;
            $numericBase = true;
        } else {
            $varRef = substr($base, 1);
            $numericBase = false;
        }

        $segments = $this->lexer->lexVarSegments($varRef);
        $varName = array_shift($segments);

        $foreachProperty = null;
        if (count($segments) > 0 && substr($segments[count($segments) - 1], 0, 1) === '@') {
            $foreachProperty = substr(array_pop($segments), 1);
        }

        if ($varName === 'smarty' || $varName === 'douview') {
            $output = $this->resolver->resolve($segments, $varName);
        } elseif (is_numeric($varName) && $numericBase) {
            if (count($segments) > 0) {
                $varName .= implode('', $segments);
                $segments = array();
            }
            $output = $varName;
        } else {
            $output = "\$ctx->vars['$varName']";
        }

        foreach ($segments as $seg) {
            if (substr($seg, 0, 1) === '[') {
                $inner = substr($seg, 1, -1);
                if (is_numeric($inner)) {
                    $output .= "[$inner]";
                } elseif ($inner !== '' && substr($inner, 0, 1) === '$') {
                    if (strpos($inner, '.') !== false) {
                        $output .= '[' . $this->parser->compileValue($inner, false) . ']';
                    } else {
                        $output .= "[\$ctx->vars['" . substr($inner, 1) . "']]";
                    }
                } else {
                    $this->parser->syntaxError("invalid array index '$inner'");
                }
            } elseif (substr($seg, 0, 1) === '.') {
                if (substr($seg, 1, 1) === '$') {
                    $output .= "[\$ctx->vars['" . substr($seg, 2) . "']]";
                } else {
                    $output .= "['" . substr($seg, 1) . "']";
                }
            } elseif (substr($seg, 0, 2) === '->') {
                $this->parser->syntaxError('call to object member is not allowed');
            } elseif (substr($seg, 0, 1) === '(') {
                $this->parser->syntaxError('function call is not allowed');
            } else {
                $output .= $seg;
            }
        }

        if ($foreachProperty !== null) {
            $output = $this->emitForeachProperty($varName, $foreachProperty);
        }

        return $output;
    }

    /**
     * 生成 $item@iteration 等 foreach 属性的运行期取值代码。
     *
     * @param string $varName
     * @param string $property
     * @return string
     */
    public function emitForeachProperty($varName, $property)
    {
        switch ($property) {
            case 'iteration':
            case 'index':
            case 'total':
            case 'first':
            case 'last':
            case 'show':
                return "\$ctx->loopProp('$varName', '$property')";
            default:
                $this->parser->syntaxError("unknown foreach property '@$property'");
                return '';
        }
    }

    /**
     * 把修饰器链包成嵌套 $ctx->filter(...) 调用。
     *
     * @param string $output 被包裹的表达式
     * @param array $modifiers [ ['name' => 修饰器名(可含@), 'args' => ExprNode[]] ]
     * @return string
     */
    public function applyModifiers($output, array $modifiers)
    {
        foreach ($modifiers as $modifier) {
            $name = ltrim($modifier['name'], '@');
            if ($name === 'smarty') {
                continue;
            }
            $args = array();
            foreach ($modifier['args'] as $argNode) {
                $args[] = $this->emit($argNode);
            }
            $argString = count($args) > 0 ? ', ' . implode(', ', $args) : '';
            $output = '$ctx->filter(\'' . $name . '\', ' . $output . $argString . ')';
        }

        return $output;
    }

    /**
     * 展开双引号文本中的内嵌变量（$word[...] 与 `$var` 反引号形式）。
     *
     * @param string $str 含两端双引号的字符串
     * @return string
     */
    public function expandQuotedText($str)
    {
        $map = array();
        $n = strlen($str);
        $i = 0;
        while ($i < $n) {
            $c = $str[$i];
            if ($c === '`') {
                $j = $i + 1;
                while ($j < $n && $str[$j] !== '`') {
                    $j++;
                }
                $inner = substr($str, $i + 1, $j - $i - 1);
                $whole = substr($str, $i, $j - $i + 1);
                $precededByEscape = ($i > 0 && $str[$i - 1] === '\\');
                if (!$precededByEscape && $inner !== '' && substr($inner, 0, 1) === '$') {
                    $map[$whole] = '".(' . $this->emitVar($inner) . ')."';
                }
                $i = $j + 1;
                continue;
            }
            if ($c === '$' && ($i === 0 || $str[$i - 1] !== '\\')) {
                $j = $i + 1;
                while ($j < $n && ($str[$j] === '_' || ctype_alnum($str[$j]))) {
                    $j++;
                }
                while ($j < $n && $str[$j] === '[') {
                    $k = $j + 1;
                    while ($k < $n && ctype_alnum($str[$k])) {
                        $k++;
                    }
                    if ($k < $n && $str[$k] === ']') {
                        $j = $k + 1;
                    } else {
                        break;
                    }
                }
                $whole = substr($str, $i, $j - $i);
                if (strlen($whole) > 1) {
                    $map[$whole] = '".(' . $this->emitVar($whole) . ')."';
                }
                $i = $j;
                continue;
            }
            $i++;
        }

        if (!empty($map)) {
            $str = strtr($str, $map);
            $str = $this->removeEmptyConcats($str);
        }

        $str = $this->collapseSimpleQuoted($str);

        return $str;
    }

    /**
     * 单趟移除空字符串拼接 `.""` 与 `""\.`（后者首引号不得被反斜杠转义）。
     *
     * @param string $str
     * @return string
     */
    private function removeEmptyConcats($str)
    {
        $result = '';
        $n = strlen($str);
        $i = 0;
        while ($i < $n) {
            if ($i + 2 < $n && $str[$i] === '.' && $str[$i + 1] === '"' && $str[$i + 2] === '"') {
                $i += 3;
                continue;
            }
            if ($i + 2 < $n && $str[$i] === '"' && $str[$i + 1] === '"' && $str[$i + 2] === '.'
                && ($i === 0 || $str[$i - 1] !== '\\')
            ) {
                $i += 3;
                continue;
            }
            $result .= $str[$i];
            $i++;
        }

        return $result;
    }

    /**
     * 把纯简单文本的双引号串折叠为单引号串（等价旧 `~^"([\s\w]+)"$~` → '\1'）。
     *
     * @param string $str
     * @return string
     */
    private function collapseSimpleQuoted($str)
    {
        $n = strlen($str);
        if ($n < 2 || $str[0] !== '"' || $str[$n - 1] !== '"') {
            return $str;
        }
        $inner = substr($str, 1, -1);
        if ($inner === '') {
            return $str;
        }
        $len = strlen($inner);
        for ($k = 0; $k < $len; $k++) {
            $ch = $inner[$k];
            if (!($ch === '_' || ctype_alnum($ch) || ctype_space($ch))) {
                return $str;
            }
        }

        return "'" . $inner . "'";
    }
}
