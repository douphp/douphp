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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 表达式词法扫描器（Twig 式 cursor 扫描，**零 preg**）。
 *
 * 提供四类纯函数扫描，供 {@see ExprParser} / {@see ExprEmitter} 消费：
 *   1) {@see splitValueAndModifiers()} 把「值 + 修饰器链」切成 base 与 mods；
 *   2) {@see lexVarSegments()} 把变量路径切成访问段（名 + .键 / .$键 / [..] / @属性）；
 *   3) {@see splitMath()} 把变量基串按顶层算术算子切成操作数 + 算子；
 *   4) {@see lexModifierChain()} 把修饰器串切成 [{name, argsRaw}]；
 *   5) {@see tokenizeCondition()} 把 {if} 条件切成原子（变量 / 字符串 / 运算符 / 数字 / 词）。
 *
 * 切分边界与旧 Smarty 正则（_dvar_regexp / _mod_regexp / if tokenizer）逐字节对齐，
 * 保证 lowering 产物不变（渲染回归由 devtools/douview-list-tag-smoke.php 冒烟覆盖）。
 */
class ExprLexer
{
    /** @var string 算术算子字符（- 需排除 ->，由扫描处单独判定） */
    const MATH_OPS = '+*/%';

    /**
     * 判断是否「单词字符」（\w）。
     *
     * @param string $c
     * @return bool
     */
    private function isWord($c)
    {
        return $c === '_' || ctype_alnum($c);
    }

    /**
     * 把「值 + 修饰器链」按首个顶层 `|`（引号/方括号外）切开。
     *
     * @param string $val 已 trim 的值表达式
     * @return array array('base' => 基串, 'mods' => 修饰器串含前导 `|`，无则 '')
     */
    public function splitValueAndModifiers($val)
    {
        $n = strlen($val);
        $inq = '';
        $depth = 0;
        for ($i = 0; $i < $n; $i++) {
            $c = $val[$i];
            if ($inq !== '') {
                if ($c === '\\') {
                    $i++;
                    continue;
                }
                if ($c === $inq) {
                    $inq = '';
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $inq = $c;
            } elseif ($c === '[') {
                $depth++;
            } elseif ($c === ']') {
                $depth--;
            } elseif ($c === '|' && $depth === 0) {
                return array('base' => substr($val, 0, $i), 'mods' => substr($val, $i));
            }
        }

        return array('base' => $val, 'mods' => '');
    }

    /**
     * 把变量路径切成访问段。首段为变量名，其后为 .键 / .$键 / [..] / @属性 / ->成员。
     *
     * @param string $varRef 去前导 `$` 后的变量路径（数字基串则为原串）
     * @return string[] 访问段序列（首段为名）
     */
    public function lexVarSegments($varRef)
    {
        $segments = array();
        $n = strlen($varRef);
        $i = 0;

        // 首段：前导单词
        $name = '';
        while ($i < $n && $this->isWord($varRef[$i])) {
            $name .= $varRef[$i];
            $i++;
        }
        $segments[] = $name;

        while ($i < $n) {
            $c = $varRef[$i];
            if ($c === '[') {
                $j = $i + 1;
                while ($j < $n && $varRef[$j] !== ']') {
                    $j++;
                }
                $segments[] = substr($varRef, $i, $j - $i + 1);
                $i = $j + 1;
            } elseif ($c === '.') {
                $seg = '.';
                $i++;
                if ($i < $n && $varRef[$i] === '$') {
                    $seg .= '$';
                    $i++;
                }
                while ($i < $n && $this->isWord($varRef[$i])) {
                    $seg .= $varRef[$i];
                    $i++;
                }
                $segments[] = $seg;
            } elseif ($c === '@') {
                $seg = '@';
                $i++;
                while ($i < $n && $this->isWord($varRef[$i])) {
                    $seg .= $varRef[$i];
                    $i++;
                }
                $segments[] = $seg;
            } elseif ($c === '-' && $i + 1 < $n && $varRef[$i + 1] === '>') {
                $seg = '->';
                $i += 2;
                if ($i < $n && $varRef[$i] === '.') {
                    $seg .= '.';
                    $i++;
                }
                if ($i < $n && $varRef[$i] === '$') {
                    $seg .= '$';
                    $i++;
                }
                while ($i < $n && $this->isWord($varRef[$i])) {
                    $seg .= $varRef[$i];
                    $i++;
                }
                $segments[] = $seg;
            } else {
                // 兜底：非空白串作为单段
                $seg = '';
                while ($i < $n && !ctype_space($varRef[$i])) {
                    $seg .= $varRef[$i];
                    $i++;
                }
                if ($seg === '') {
                    $i++;
                } else {
                    $segments[] = $seg;
                }
            }
        }

        return $segments;
    }

    /**
     * 把变量基串按顶层算术算子（+ * / %，或非 -> 的 -）切成操作数 + 算子。
     *
     * @param string $base 变量基串（含前导 $ 或数字）
     * @return array array('operands' => string[], 'ops' => string[])
     */
    public function splitMath($base)
    {
        $operands = array();
        $ops = array();
        $n = strlen($base);
        $depth = 0;
        $inq = '';
        $cur = '';
        for ($i = 0; $i < $n; $i++) {
            $c = $base[$i];
            if ($inq !== '') {
                $cur .= $c;
                if ($c === '\\' && $i + 1 < $n) {
                    $cur .= $base[$i + 1];
                    $i++;
                } elseif ($c === $inq) {
                    $inq = '';
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $inq = $c;
                $cur .= $c;
                continue;
            }
            if ($c === '[') {
                $depth++;
                $cur .= $c;
                continue;
            }
            if ($c === ']') {
                $depth--;
                $cur .= $c;
                continue;
            }
            $isMath = false;
            if ($depth === 0) {
                if (strpos(self::MATH_OPS, $c) !== false) {
                    $isMath = true;
                } elseif ($c === '-' && !($i + 1 < $n && $base[$i + 1] === '>')) {
                    $isMath = true;
                }
            }
            if ($isMath) {
                $operands[] = $cur;
                $ops[] = $c;
                $cur = '';
            } else {
                $cur .= $c;
            }
        }
        $operands[] = $cur;

        return array('operands' => $operands, 'ops' => $ops);
    }

    /**
     * 把修饰器串切成 [{name, argsRaw}]。
     *
     * @param string $modsRaw 修饰器串（含前导 `|`）
     * @return array 每项 array('name' => 修饰器名(可含前导@), 'argsRaw' => string[])
     */
    public function lexModifierChain($modsRaw)
    {
        $result = array();
        $n = strlen($modsRaw);
        $i = 0;
        while ($i < $n) {
            if ($modsRaw[$i] !== '|') {
                $i++;
                continue;
            }
            $i++; // 跳过 |
            // 修饰器名：可选 @ + 单词
            $name = '';
            if ($i < $n && $modsRaw[$i] === '@') {
                $name .= '@';
                $i++;
            }
            while ($i < $n && $this->isWord($modsRaw[$i])) {
                $name .= $modsRaw[$i];
                $i++;
            }
            if ($name === '' || $name === '@') {
                continue;
            }
            // 参数：(:arg)*
            $args = array();
            while ($i < $n && $modsRaw[$i] === ':') {
                $i++; // 跳过 :
                $arg = '';
                $inq = '';
                while ($i < $n) {
                    $c = $modsRaw[$i];
                    if ($inq !== '') {
                        $arg .= $c;
                        if ($c === '\\' && $i + 1 < $n) {
                            $arg .= $modsRaw[$i + 1];
                            $i++;
                        } elseif ($c === $inq) {
                            $inq = '';
                        }
                        $i++;
                        continue;
                    }
                    if ($c === '"' || $c === "'") {
                        $inq = $c;
                        $arg .= $c;
                        $i++;
                        continue;
                    }
                    if ($c === ':' || $c === '|') {
                        break;
                    }
                    $arg .= $c;
                    $i++;
                }
                $args[] = $arg;
            }
            $result[] = array('name' => $name, 'argsRaw' => $args);
        }

        return $result;
    }

    /**
     * 把 {if} 条件切成原子序列（与旧 if tokenizer 边界对齐）。
     *
     * @param string $cond 条件表达式
     * @return string[] 原子序列
     */
    public function tokenizeCondition($cond)
    {
        $tokens = array();
        $n = strlen($cond);
        $i = 0;
        $multiOps = array('!==', '===', '==', '!=', '<>', '<<', '>>', '<=', '>=', '&&', '||');

        while ($i < $n) {
            $c = $cond[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }

            // 变量原子：$ + 路径 + mathtail + 修饰器
            if ($c === '$') {
                list($atom, $i) = $this->consumeVarAtom($cond, $i);
                $tokens[] = $atom;
                continue;
            }

            // 字符串原子：引号串 + 修饰器
            if ($c === '"' || $c === "'") {
                list($atom, $i) = $this->consumeStringAtom($cond, $i);
                $tokens[] = $atom;
                continue;
            }

            // 数字：-?0x.. | -?\d+(.\d+)? | .\d+
            $num = $this->consumeNumber($cond, $i);
            if ($num !== null) {
                $tokens[] = $num;
                $i += strlen($num);
                continue;
            }

            // 多字符运算符
            $matchedMulti = false;
            foreach ($multiOps as $op) {
                if (substr($cond, $i, strlen($op)) === $op) {
                    $tokens[] = $op;
                    $i += strlen($op);
                    $matchedMulti = true;
                    break;
                }
            }
            if ($matchedMulti) {
                continue;
            }

            // 单字符运算符
            if (strpos('()!^=&~<>|%+-*/,@', $c) !== false) {
                $tokens[] = $c;
                $i++;
                continue;
            }

            // 单词
            if ($this->isWord($c)) {
                $word = '';
                while ($i < $n && $this->isWord($cond[$i])) {
                    $word .= $cond[$i];
                    $i++;
                }
                $tokens[] = $word;
                continue;
            }

            // 兜底：非空白串
            $rest = '';
            while ($i < $n && !ctype_space($cond[$i])) {
                $rest .= $cond[$i];
                $i++;
            }
            $tokens[] = $rest;
        }

        return $tokens;
    }

    /**
     * 消费一个变量原子（$ + 路径 + 算术尾 + 修饰器），返回 [atom, newPos]。
     *
     * @param string $s
     * @param int $i 起始位（指向 $）
     * @return array
     */
    private function consumeVarAtom($s, $i)
    {
        $n = strlen($s);
        $start = $i;
        $i++; // $
        // 名 + 访问段
        while ($i < $n && $this->isWord($s[$i])) {
            $i++;
        }
        while ($i < $n) {
            $c = $s[$i];
            if ($c === '.') {
                $i++;
                if ($i < $n && $s[$i] === '$') {
                    $i++;
                }
                while ($i < $n && $this->isWord($s[$i])) {
                    $i++;
                }
            } elseif ($c === '[') {
                while ($i < $n && $s[$i] !== ']') {
                    $i++;
                }
                if ($i < $n) {
                    $i++; // ]
                }
            } elseif ($c === '@') {
                $i++;
                while ($i < $n && $this->isWord($s[$i])) {
                    $i++;
                }
            } elseif ($c === '-' && $i + 1 < $n && $s[$i + 1] === '>') {
                $i += 2;
                if ($i < $n && $s[$i] === '.') {
                    $i++;
                }
                if ($i < $n && $s[$i] === '$') {
                    $i++;
                }
                while ($i < $n && $this->isWord($s[$i])) {
                    $i++;
                }
            } else {
                break;
            }
        }
        // 算术尾：mathop(+*/% 或非-> 的-) 后接 mathvar 串
        if ($i < $n) {
            $c = $s[$i];
            $isMath = strpos(self::MATH_OPS, $c) !== false
                || ($c === '-' && !($i + 1 < $n && $s[$i + 1] === '>'));
            if ($isMath) {
                $i++;
                while ($i < $n && strpos('$.+-*/%>[]', $s[$i]) !== false || ($i < $n && $this->isWord($s[$i]))) {
                    $i++;
                }
            }
        }
        $i = $this->consumeModifierChainAt($s, $i);

        return array(substr($s, $start, $i - $start), $i);
    }

    /**
     * 消费一个字符串原子（引号串 + 修饰器），返回 [atom, newPos]。
     *
     * @param string $s
     * @param int $i 起始位（指向引号）
     * @return array
     */
    private function consumeStringAtom($s, $i)
    {
        $n = strlen($s);
        $start = $i;
        $q = $s[$i];
        $i++;
        while ($i < $n) {
            $c = $s[$i];
            if ($c === '\\' && $i + 1 < $n) {
                $i += 2;
                continue;
            }
            if ($c === $q) {
                $i++;
                break;
            }
            $i++;
        }
        $i = $this->consumeModifierChainAt($s, $i);

        return array(substr($s, $start, $i - $start), $i);
    }

    /**
     * 从位置 $i 起消费修饰器链（(|name(:arg)*)*，`||` 视为逻辑或不消费），返回链结束后的新位置。
     *
     * @param string $s
     * @param int $i 起始位
     * @return int
     */
    private function consumeModifierChainAt($s, $i)
    {
        $n = strlen($s);
        while ($i < $n && $s[$i] === '|' && !($i + 1 < $n && $s[$i + 1] === '|')) {
            $i++; // |
            if ($i < $n && $s[$i] === '@') {
                $i++;
            }
            while ($i < $n && $this->isWord($s[$i])) {
                $i++;
            }
            while ($i < $n && $s[$i] === ':') {
                $i++;
                $inq = '';
                while ($i < $n) {
                    $cc = $s[$i];
                    if ($inq !== '') {
                        if ($cc === '\\' && $i + 1 < $n) {
                            $i++;
                        } elseif ($cc === $inq) {
                            $inq = '';
                        }
                        $i++;
                        continue;
                    }
                    if ($cc === '"' || $cc === "'") {
                        $inq = $cc;
                        $i++;
                        continue;
                    }
                    if ($cc === ':' || $cc === '|' || ctype_space($cc)) {
                        break;
                    }
                    $i++;
                }
            }
        }

        return $i;
    }

    /**
     * 尝试在位置 $i 读取数字字面量；不匹配返回 null。
     *
     * @param string $s
     * @param int $i
     * @return string|null
     */
    private function consumeNumber($s, $i)
    {
        $n = strlen($s);
        $j = $i;
        if ($j < $n && $s[$j] === '-') {
            $j++;
        }
        // 0x 十六进制
        if ($j + 1 < $n && $s[$j] === '0' && ($s[$j + 1] === 'x' || $s[$j + 1] === 'X')) {
            $k = $j + 2;
            while ($k < $n && ctype_xdigit($s[$k])) {
                $k++;
            }
            if ($k > $j + 2) {
                return substr($s, $i, $k - $i);
            }
        }
        // 十进制 \d+(.\d+)?
        if ($j < $n && ctype_digit($s[$j])) {
            $k = $j;
            while ($k < $n && ctype_digit($s[$k])) {
                $k++;
            }
            if ($k < $n && $s[$k] === '.' && $k + 1 < $n && ctype_digit($s[$k + 1])) {
                $k++;
                while ($k < $n && ctype_digit($s[$k])) {
                    $k++;
                }
            }
            return substr($s, $i, $k - $i);
        }
        // .\d+（无前导 -）
        if ($s[$i] === '.' && $i + 1 < $n && ctype_digit($s[$i + 1])) {
            $k = $i + 1;
            while ($k < $n && ctype_digit($s[$k])) {
                $k++;
            }
            return substr($s, $i, $k - $i);
        }

        return null;
    }
}
