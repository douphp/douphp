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
 * 表达式前端 orchestrator：协调 {@see ExprLexer}（扫描）→ AST（{@see ExprNode}）→
 * {@see ExprEmitter}（emit）三段，把模板值表达式与 {@see compileCondition() {if} 条件}
 * lowering 为 $ctx PHP。零 preg，产物与旧 ExpressionCompiler 逐字节对齐。
 */
class ExprParser
{
    /** @var string 左定界符（供 $smarty.ldelim） */
    private $leftDelimiter;
    /** @var string 右定界符（供 $smarty.rdelim） */
    private $rightDelimiter;
    /** @var string|null 当前模板资源名（错误定位 + $smarty.template） */
    private $currentFile = null;
    /** @var int 当前行号（错误定位） */
    private $currentLineNo = 1;
    /** @var array 字面量保留词 */
    private $permittedTokens = array('true', 'false', 'yes', 'no', 'on', 'off', 'null');

    /** @var ExprLexer */
    private $lexer;
    /** @var ExprEmitter */
    private $emitter;
    /** @var BuiltinVarResolver */
    private $resolver;

    /** @var array if 条件里直接保留的运算符 / 括号 token */
    private static $conditionPassThrough = array(
        '!', '%', '!==', '==', '===', '>', '<', '!=', '<>', '<<', '>>', '<=', '>=',
        '&&', '||', '|', '^', '&', '~', ')', ',', '+', '-', '*', '/', '@', '(',
    );

    /**
     * @param string $leftDelimiter
     * @param string $rightDelimiter
     */
    public function __construct($leftDelimiter = '{', $rightDelimiter = '}')
    {
        $this->leftDelimiter = $leftDelimiter;
        $this->rightDelimiter = $rightDelimiter;
        $this->lexer = new ExprLexer();
        $this->resolver = new BuiltinVarResolver($this);
        $this->emitter = new ExprEmitter($this, $this->lexer, $this->resolver);
    }

    /**
     * 设置错误定位上下文。
     *
     * @param string $file
     * @param int $line
     * @return void
     */
    public function setLocation($file, $line)
    {
        $this->currentFile = $file;
        $this->currentLineNo = (int) $line;
    }

    /** @return string|null */
    public function getCurrentFile()
    {
        return $this->currentFile;
    }

    /** @return string */
    public function getLeftDelimiter()
    {
        return $this->leftDelimiter;
    }

    /** @return string */
    public function getRightDelimiter()
    {
        return $this->rightDelimiter;
    }

    /** @return array */
    public function getPermittedTokens()
    {
        return $this->permittedTokens;
    }

    /**
     * 把「值 + 修饰器链」lowering 为 PHP 表达式（等价旧 parseVarProps）。
     *
     * @param string $val
     * @param bool $skipDefaults 兼容签名（本系统默认修饰器为空，无副作用）
     * @return string
     */
    public function compileValue($val, $skipDefaults = false)
    {
        $node = $this->parseValue($val, $skipDefaults);

        return $this->emitter->emit($node);
    }

    /**
     * 把值表达式解析为 AST 节点。
     *
     * @param string $val
     * @param bool $skipDefaults
     * @return ExprNode
     */
    public function parseValue($val, $skipDefaults = false)
    {
        $val = trim($val);
        if ($val === '') {
            return new ExprNode(ExprNodeType::RAW, '');
        }

        $c0 = $val[0];

        if ($c0 === '$') {
            $sp = $this->lexer->splitValueAndModifiers($val);
            return $this->wrapModifiers($this->parseVarBase($sp['base']), $sp['mods']);
        }
        if ($c0 === '"') {
            $sp = $this->lexer->splitValueAndModifiers($val);
            $node = new ExprNode(ExprNodeType::STRING, $sp['base']);
            $node->meta['quote'] = '"';
            return $this->wrapModifiers($node, $sp['mods']);
        }
        if ($c0 === "'") {
            $sp = $this->lexer->splitValueAndModifiers($val);
            $node = new ExprNode(ExprNodeType::STRING, $sp['base']);
            $node->meta['quote'] = "'";
            return $this->wrapModifiers($node, $sp['mods']);
        }
        if ($this->isNumericStart($val)) {
            $sp = $this->lexer->splitValueAndModifiers($val);
            $node = new ExprNode(ExprNodeType::NUMBER, $sp['base']);
            return $this->wrapModifiers($node, $sp['mods']);
        }

        // 裸词 / 字面量：不切修饰器（与旧 parseVarProps 末两分支一致）
        if (in_array($val, $this->permittedTokens) || is_numeric($val)) {
            return new ExprNode(ExprNodeType::RAW, $val);
        }

        return new ExprNode(ExprNodeType::BAREWORD, $val);
    }

    /**
     * 把变量基串解析为 VAR_PATH 或 MATH 节点。
     *
     * @param string $base
     * @return ExprNode
     */
    private function parseVarBase($base)
    {
        $sm = $this->lexer->splitMath($base);
        if (count($sm['operands']) > 1) {
            $node = new ExprNode(ExprNodeType::MATH);
            foreach ($sm['operands'] as $operand) {
                $node->children[] = $this->parseOperand($operand);
            }
            $node->meta['ops'] = $sm['ops'];
            return $node;
        }

        return new ExprNode(ExprNodeType::VAR_PATH, $base);
    }

    /**
     * 把单个算术操作数解析为节点。
     *
     * @param string $op
     * @return ExprNode
     */
    private function parseOperand($op)
    {
        if ($op !== '' && $op[0] === '$') {
            return new ExprNode(ExprNodeType::VAR_PATH, $op);
        }
        if (is_numeric($op)) {
            return new ExprNode(ExprNodeType::NUMBER, $op);
        }

        return new ExprNode(ExprNodeType::VAR_PATH, $op);
    }

    /**
     * 用修饰器链包裹基节点。
     *
     * @param ExprNode $node
     * @param string $modsRaw 含前导 `|` 的修饰器串（空则原样返回）
     * @return ExprNode
     */
    private function wrapModifiers(ExprNode $node, $modsRaw)
    {
        if ($modsRaw === '') {
            return $node;
        }
        $chain = $this->lexer->lexModifierChain($modsRaw);
        if (empty($chain)) {
            return $node;
        }
        $modifiers = array();
        foreach ($chain as $modifier) {
            $args = array();
            foreach ($modifier['argsRaw'] as $argRaw) {
                $args[] = $this->parseValue($argRaw, false);
            }
            $modifiers[] = array('name' => $modifier['name'], 'args' => $args);
        }
        $wrapped = new ExprNode(ExprNodeType::MODIFIED);
        $wrapped->children[] = $node;
        $wrapped->meta['modifiers'] = $modifiers;

        return $wrapped;
    }

    /**
     * 编译 {if}/{elseif} 条件为 PHP（结构 push/pop 由 Parser 负责，本方法仅产条件片段）。
     *
     * @param string $tag_args 条件表达式
     * @param bool $elseif 是否 elseif
     * @return string 形如 '<?php if (...): ?>'
     */
    public function compileCondition($tag_args, $elseif)
    {
        $tokens = $this->lexer->tokenizeCondition($tag_args);

        if (empty($tokens)) {
            $msg = ($elseif ? "'elseif'" : "'if'") . ' statement requires arguments';
            $this->syntaxError($msg);
        }

        $open = 0;
        $close = 0;
        foreach ($tokens as $token) {
            if ($token === '(') {
                $open++;
            } elseif ($token === ')') {
                $close++;
            }
        }
        if ($open !== $close) {
            $this->syntaxError('unbalanced parenthesis in if statement');
        }

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $low = strtolower($token);

            if (in_array($token, self::$conditionPassThrough, true)) {
                continue;
            }

            switch ($low) {
                case 'eq':
                    $tokens[$i] = '==';
                    break;
                case 'ne':
                case 'neq':
                    $tokens[$i] = '!=';
                    break;
                case 'lt':
                    $tokens[$i] = '<';
                    break;
                case 'le':
                case 'lte':
                    $tokens[$i] = '<=';
                    break;
                case 'gt':
                    $tokens[$i] = '>';
                    break;
                case 'ge':
                case 'gte':
                    $tokens[$i] = '>=';
                    break;
                case 'and':
                    $tokens[$i] = '&&';
                    break;
                case 'or':
                    $tokens[$i] = '||';
                    break;
                case 'not':
                    $tokens[$i] = '!';
                    break;
                case 'mod':
                    $tokens[$i] = '%';
                    break;
                default:
                    if ($this->isFuncName($token)) {
                        $this->syntaxError("'$token' not allowed in if statement");
                    } elseif ($this->isVarLike($token)
                        && strpos('+-*/^%&|', substr($token, -1)) === false
                        && isset($tokens[$i + 1]) && $tokens[$i + 1] === '('
                    ) {
                        $this->syntaxError("variable function call '$token' not allowed in if statement");
                    } elseif ($this->isVarLike($token)) {
                        $tokens[$i] = $this->compileValue($token, false);
                    } elseif (is_numeric($token)) {
                        // 数字，原样保留
                    } else {
                        $this->syntaxError("unidentified token '$token'");
                    }
                    break;
            }
        }

        $keyword = $elseif ? 'elseif' : 'if';

        return '<?php ' . $keyword . ' (' . implode(' ', $tokens) . '): ?>';
    }

    /**
     * 编译变量三元 {$a ? x : y} / {$a == 1 ? x : y} 为 PHP 内层表达式。
     *
     * 输出策略（htmlspecialchars / 换行）留给调用方（TagCompiler），本方法只产
     * 形如 '(cond) ? (true) : (false)' 的纯表达式，并回传是否带 nofilter 标记。
     *
     * @param string $raw 去定界符后的标签内容（已由 Lexer 去首尾空白）
     * @return array array('expr' => string, 'nofilter' => bool)
     */
    public function compileTernary($raw)
    {
        $parts = $this->splitTernary($raw);
        $nofilter = $this->hasNofilterFlag($raw);
        $trueRaw = trim($this->stripNofilter($parts['true']));
        $falseRaw = trim($this->stripNofilter($parts['false']));
        $condRaw = trim($parts['cond']);

        $cmp = $this->splitSimpleComparison($condRaw);
        if ($cmp !== null) {
            $condVar = $this->compileValue(trim($cmp['var']), false);
            $condOp = trim($cmp['op']);
            $condVal = trim($cmp['val']);
            if (substr($condVal, 0, 1) === '$') {
                $condVal = $this->compileValue($condVal, false);
            }
            $cond = $condVar . ' ' . $condOp . ' ' . $condVal;
        } else {
            $cond = $this->compileValue($condRaw, false);
        }
        $true = $this->compileValue($trueRaw !== '' ? $trueRaw : "''", false);
        $false = $this->compileValue($falseRaw !== '' ? $falseRaw : "''", false);

        return array(
            'expr' => '(' . $cond . ') ? (' . $true . ') : (' . $false . ')',
            'nofilter' => $nofilter,
        );
    }

    /**
     * 是否含 nofilter 标记（等价 \bnofilter\b，零 preg）。
     *
     * @param string $s
     * @return bool
     */
    public function hasNofilterFlag($s)
    {
        return $this->findWord($s, 'nofilter', 0) !== -1;
    }

    /**
     * 修饰器链是否含 HTML 转义（等价旧 |escape(?:$|\||\ *:\ *html...)，零 preg）。
     *
     * @param string $modifier
     * @return bool
     */
    public function hasHtmlEscapeModifier($modifier)
    {
        $needle = '|escape';
        $nlen = 7;
        $offset = 0;
        while (($pos = strpos($modifier, $needle, $offset)) !== false) {
            if ($this->matchEscapeHtmlSuffix($modifier, $pos + $nlen)) {
                return true;
            }
            $offset = $pos + 1;
        }

        return false;
    }

    /**
     * 是否合法标识符（等价 ^\w+$，零 preg）。
     *
     * @param string $s
     * @return bool
     */
    public function isIdentifier($s)
    {
        if ($s === '') {
            return false;
        }
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if (!($c === '_' || ctype_alnum($c))) {
                return false;
            }
        }

        return true;
    }

    /**
     * 三元拆分：cond / true / false（引号外的首个 ? 与其后引号外的首个 : 为界）。
     *
     * @param string $raw
     * @return array array('cond'=>, 'true'=>, 'false'=>)
     */
    private function splitTernary($raw)
    {
        $qPos = $this->findCharOutsideQuotes($raw, '?', 0);
        if ($qPos === -1) {
            return array('cond' => $raw, 'true' => '', 'false' => '');
        }
        $cond = substr($raw, 0, $qPos);
        $cPos = $this->findCharOutsideQuotes($raw, ':', $qPos + 1);
        if ($cPos === -1) {
            return array('cond' => $cond, 'true' => substr($raw, $qPos + 1), 'false' => '');
        }

        return array(
            'cond' => $cond,
            'true' => substr($raw, $qPos + 1, $cPos - $qPos - 1),
            'false' => substr($raw, $cPos + 1),
        );
    }

    /**
     * 查找引号外的指定字符首次出现位置（自 $from 起计，引号状态从串首跟踪）。
     *
     * @param string $s
     * @param string $char 目标字符
     * @param int $from 起始下标
     * @return int 命中下标；未命中返回 -1
     */
    private function findCharOutsideQuotes($s, $char, $from)
    {
        $n = strlen($s);
        $inq = '';
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($inq !== '') {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $inq) {
                    $inq = '';
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $inq = $c;
                continue;
            }
            if ($i >= $from && $c === $char) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * 简单比较拆分：^($var)\s*(op)\s*(.+)$（op ∈ == != <> >= <= > <）。
     *
     * @param string $cond
     * @return array|null array('var'=>, 'op'=>, 'val'=>)；不匹配返回 null
     */
    private function splitSimpleComparison($cond)
    {
        $len = strlen($cond);
        if ($len === 0 || $cond[0] !== '$') {
            return null;
        }
        $i = 1;
        while ($i < $len && $this->isVarPathChar($cond[$i])) {
            $i++;
        }
        if ($i === 1) {
            return null;
        }
        $var = substr($cond, 0, $i);

        $j = $i;
        while ($j < $len && $this->isWhitespace($cond[$j])) {
            $j++;
        }
        $op = $this->matchComparisonOp($cond, $j);
        if ($op === null) {
            return null;
        }
        $j += strlen($op);
        while ($j < $len && $this->isWhitespace($cond[$j])) {
            $j++;
        }
        $val = substr($cond, $j);
        if ($val === '') {
            return null;
        }

        return array('var' => $var, 'op' => $op, 'val' => $val);
    }

    /**
     * 在指定位置匹配比较运算符（按 == != <> >= <= > < 顺序，2 字符优先）。
     *
     * @param string $s
     * @param int $pos
     * @return string|null
     */
    private function matchComparisonOp($s, $pos)
    {
        $two = substr($s, $pos, 2);
        if ($two === '==' || $two === '!=' || $two === '<>' || $two === '>=' || $two === '<=') {
            return $two;
        }
        $one = substr($s, $pos, 1);
        if ($one === '>' || $one === '<') {
            return $one;
        }

        return null;
    }

    /**
     * 校验 |escape 后缀（结尾 / | / :spec），零 preg。
     *
     * @param string $s
     * @param int $i |escape 之后的下标
     * @return bool
     */
    private function matchEscapeHtmlSuffix($s, $i)
    {
        $len = strlen($s);
        if ($i >= $len) {
            return true;
        }
        if ($s[$i] === '|') {
            return true;
        }

        $j = $i;
        while ($j < $len && $s[$j] === ' ') {
            $j++;
        }
        if ($j >= $len || $s[$j] !== ':') {
            return false;
        }
        $j++;
        while ($j < $len && $s[$j] === ' ') {
            $j++;
        }

        if ($j < $len && ($s[$j] === '"' || $s[$j] === "'")) {
            if (substr($s, $j + 1, 4) === 'html' && isset($s[$j + 5]) && ($s[$j + 5] === '"' || $s[$j + 5] === "'")) {
                $k = $j + 6;
            } else {
                return false;
            }
        } elseif (substr($s, $j, 4) === 'html') {
            $k = $j + 4;
        } else {
            return false;
        }

        if ($k >= $len) {
            return true;
        }

        return $s[$k] === '|';
    }

    /**
     * 删除所有 \bnofilter\b（等价正则替换，零 preg）。
     *
     * @param string $s
     * @return string
     */
    private function stripNofilter($s)
    {
        $result = '';
        $offset = 0;
        $wlen = 8;
        while (true) {
            $pos = $this->findWord($s, 'nofilter', $offset);
            if ($pos === -1) {
                $result .= substr($s, $offset);
                break;
            }
            $result .= substr($s, $offset, $pos - $offset);
            $offset = $pos + $wlen;
        }

        return $result;
    }

    /**
     * 查找以词边界包裹的子串首次出现位置（等价 \bword\b）。
     *
     * @param string $s
     * @param string $word
     * @param int $from 起始下标
     * @return int 命中下标；未命中返回 -1
     */
    private function findWord($s, $word, $from)
    {
        $wlen = strlen($word);
        $slen = strlen($s);
        $offset = $from;
        while ($offset <= $slen && ($pos = strpos($s, $word, $offset)) !== false) {
            $before = ($pos === 0) ? '' : $s[$pos - 1];
            $afterIdx = $pos + $wlen;
            $after = ($afterIdx >= $slen) ? '' : $s[$afterIdx];
            if (!$this->isWordChar($before) && !$this->isWordChar($after)) {
                return $pos;
            }
            $offset = $pos + 1;
        }

        return -1;
    }

    /**
     * 是否词字符 [A-Za-z0-9_]（空字符串视为非词字符，对齐 \b 语义）。
     *
     * @param string $c
     * @return bool
     */
    private function isWordChar($c)
    {
        if ($c === '') {
            return false;
        }

        return $c === '_' || ctype_alnum($c);
    }

    /**
     * 是否变量路径字符 [\w.\[\]$]。
     *
     * @param string $c
     * @return bool
     */
    private function isVarPathChar($c)
    {
        return $c === '_' || $c === '.' || $c === '[' || $c === ']' || $c === '$' || ctype_alnum($c);
    }

    /**
     * 是否空白（对齐正则 \s：空格 / \t / \n / \r / \f / \v）。
     *
     * @param string $c
     * @return bool
     */
    private function isWhitespace($c)
    {
        return $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f" || $c === "\x0B";
    }

    /**
     * 去引号。
     *
     * @param string $string
     * @return string
     */
    public function dequote($string)
    {
        if ((substr($string, 0, 1) === "'" || substr($string, 0, 1) === '"')
            && substr($string, -1) === substr($string, 0, 1)
        ) {
            return substr($string, 1, -1);
        }

        return $string;
    }

    /**
     * 编译期语法错误。
     *
     * @param string $error_msg
     * @return void
     * @throws \RuntimeException
     */
    public function syntaxError($error_msg)
    {
        throw new \RuntimeException('DouView syntax error: ' . $error_msg
            . ' [in ' . $this->currentFile . ' line ' . $this->currentLineNo . ']');
    }

    /**
     * 是否纯函数名（^[a-zA-Z_]\w*$）。
     *
     * @param string $token
     * @return bool
     */
    private function isFuncName($token)
    {
        if ($token === '') {
            return false;
        }
        $c0 = $token[0];
        if (!($c0 === '_' || ctype_alpha($c0))) {
            return false;
        }
        $len = strlen($token);
        for ($i = 1; $i < $len; $i++) {
            $c = $token[$i];
            if (!($c === '_' || ctype_alnum($c))) {
                return false;
            }
        }

        return true;
    }

    /**
     * 是否变量/字符串原子（以 $ 或引号起头）。
     *
     * @param string $token
     * @return bool
     */
    private function isVarLike($token)
    {
        if ($token === '') {
            return false;
        }
        $c0 = $token[0];

        return $c0 === '$' || $c0 === '"' || $c0 === "'";
    }

    /**
     * 是否数字起头（digit 或 -digit）。
     *
     * @param string $val
     * @return bool
     */
    private function isNumericStart($val)
    {
        $c0 = $val[0];
        if (ctype_digit($c0)) {
            return true;
        }

        return $c0 === '-' && isset($val[1]) && ctype_digit($val[1]);
    }
}
