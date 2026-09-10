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

use Dou\Core\Web\Template\Expression\ExprParser;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 标签属性解析器（零 preg）：把 `name="val" name2=$var` 形态的属性串切成
 * 「属性名 => 已 lowering 的 PHP 表达式」映射，值经 {@see ExprParser::compileValue()} 处理。
 *
 * 切分边界与旧 ExpressionCompiler::parseAttrs 对齐：以空白 / `=` 分隔，引号串整体保留。
 */
class AttrParser
{
    /** @var ExprParser */
    private $expr;

    /**
     * @param ExprParser $expr 值表达式前端
     */
    public function __construct(ExprParser $expr)
    {
        $this->expr = $expr;
    }

    /**
     * 解析属性串为「名 => PHP 表达式」映射。
     *
     * @param string $tag_args
     * @return array
     */
    public function parse($tag_args)
    {
        $tokens = $this->tokenize($tag_args);

        $attrs = array();
        $attr_name = '';
        $last_token = '';
        $state = 0;

        foreach ($tokens as $token) {
            switch ($state) {
                case 0:
                    if ($this->isWordToken($token)) {
                        $attr_name = $token;
                        $state = 1;
                    } else {
                        $this->expr->syntaxError("invalid attribute name: '$token'");
                    }
                    break;

                case 1:
                    if ($token === '=') {
                        $state = 2;
                    } else {
                        $this->expr->syntaxError("expecting '=' after attribute name '$last_token'");
                    }
                    break;

                case 2:
                    if ($token !== '=') {
                        if ($token === 'on' || $token === 'yes' || $token === 'true') {
                            $token = 'true';
                        } elseif ($token === 'off' || $token === 'no' || $token === 'false') {
                            $token = 'false';
                        } elseif ($token === 'null') {
                            $token = 'null';
                        } elseif ($this->isIntLiteral($token)) {
                            // 整数 / 十六进制字面量原样
                        } elseif (!$this->isVarOrStringToken($token)) {
                            $token = '"' . addslashes($token) . '"';
                        }
                        $attrs[$attr_name] = $token;
                        $state = 0;
                    } else {
                        $this->expr->syntaxError("'=' cannot be an attribute value");
                    }
                    break;
            }
            $last_token = $token;
        }

        if ($state != 0) {
            if ($state == 1) {
                $this->expr->syntaxError("expecting '=' after attribute name '$last_token'");
            } else {
                $this->expr->syntaxError('missing attribute value');
            }
        }

        foreach ($attrs as $key => $val) {
            $attrs[$key] = $this->expr->compileValue($val, false);
        }

        return $attrs;
    }

    /**
     * 把属性串切成 token 序列（属性名 / `=` / 值；引号串整体保留）。
     *
     * @param string $args
     * @return string[]
     */
    private function tokenize($args)
    {
        $tokens = array();
        $n = strlen($args);
        $i = 0;
        while ($i < $n) {
            $c = $args[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ($c === '=') {
                $tokens[] = '=';
                $i++;
                continue;
            }
            $tok = '';
            while ($i < $n) {
                $c = $args[$i];
                if (ctype_space($c) || $c === '=') {
                    break;
                }
                if ($c === '"' || $c === "'") {
                    $q = $c;
                    $tok .= $c;
                    $i++;
                    while ($i < $n) {
                        $cc = $args[$i];
                        $tok .= $cc;
                        $i++;
                        if ($cc === '\\' && $i < $n) {
                            $tok .= $args[$i];
                            $i++;
                            continue;
                        }
                        if ($cc === $q) {
                            break;
                        }
                    }
                    continue;
                }
                $tok .= $c;
                $i++;
            }
            $tokens[] = $tok;
        }

        return $tokens;
    }

    /**
     * 是否合法属性名（^\w+$）。
     *
     * @param string $token
     * @return bool
     */
    private function isWordToken($token)
    {
        if ($token === '') {
            return false;
        }
        $len = strlen($token);
        for ($i = 0; $i < $len; $i++) {
            $c = $token[$i];
            if (!($c === '_' || ctype_alnum($c))) {
                return false;
            }
        }

        return true;
    }

    /**
     * 是否整数 / 十六进制字面量（等价旧 '~^NUM|0[xX][0-9a-fA-F]+$~'：起头匹配 NUM 或结尾匹配 0x..）。
     *
     * @param string $token
     * @return bool
     */
    private function isIntLiteral($token)
    {
        $n = strlen($token);
        if ($n === 0) {
            return false;
        }
        // 左支：^-?\d+(\.\d+)?（仅锚定起头）
        $i = 0;
        if ($token[$i] === '-') {
            $i++;
        }
        if ($i < $n && ctype_digit($token[$i])) {
            return true;
        }
        // 右支：0[xX][0-9a-fA-F]+$（仅锚定结尾）
        for ($j = 0; $j + 1 < $n; $j++) {
            if ($token[$j] === '0' && ($token[$j + 1] === 'x' || $token[$j + 1] === 'X')) {
                $k = $j + 2;
                $hex = false;
                while ($k < $n && ctype_xdigit($token[$k])) {
                    $k++;
                    $hex = true;
                }
                if ($hex && $k === $n) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 是否变量 / 字符串原子（以 $ 或引号起头）。
     *
     * @param string $token
     * @return bool
     */
    private function isVarOrStringToken($token)
    {
        if ($token === '') {
            return false;
        }
        $c0 = $token[0];

        return $c0 === '$' || $c0 === '"' || $c0 === "'";
    }
}
