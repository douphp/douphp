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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 标签分类器：把 {@see Parser} 的「标签形态判定」从 {@see \Dou\Core\Web\Template\Expression\ExpressionCompiler}
 * 的公开 `_*_regexp` 字段中解耦出来，集中承载三类纯结构判定：
 *
 *   1) {@see isTernary()}    —— 变量三元 {$a ? x : y} / {$a == 1 ? x : y}
 *   2) {@see splitCommand()} —— 命令 / 修饰器链 / 参数 三段切分
 *   3) {@see isEchoCommand()}—— 命令是否为「变量 / 对象 / 数字常量」（直接输出）
 *
 * 本类只做「这是什么标签」的判定，不做表达式 lowering（emit PHP 仍由 ExpressionCompiler/
 * CodeGenerator 负责）。当前实现沿用与旧编译器一致的构建块正则以保证分类字节等价；
 * 后续阶段会把内部判定切换为 ExprLexer 子集 / 状态机（见开发计划 preg 治理边界）。
 */
class TagClassifier
{
    /** @var string 命令段：数字常量 | 对象调用 | 变量 | （可带前导 /）函数名 */
    private $commandRegexp;
    /** @var string 修饰器链（单段，可 * 重复） */
    private $modRegexp;
    /** @var string 「变量 / 对象 / 数字常量」整体（用于 echo 判定） */
    private $echoRegexp;

    public function __construct()
    {
        $dbQstr = '"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"';
        $siQstr = '\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'';
        $qstr = '(?:' . $dbQstr . '|' . $siQstr . ')';
        $varBracket = '\[\$?[\w\.]+\]';
        $numConst = '(?:\-?\d+(?:\.\d+)?)';

        $dvarMath = '(?:[\+\*\/\%]|(?:-(?!>)))';
        // 算术操作数字符类含 `@`：让 foreach 属性 `$item@prop` 在非首操作数位置（如
        // `$a-$item@prop*N`）也归类为 echo/value 标签，与 `$smarty.foreach.X.prop`
        // 在算术链中等价。
        $dvarMathVar = '[\$\w\.\+\-\*\/\%\d\>\[\]@]';
        $dvarGuts = '\w+(?:' . $varBracket
            . ')*(?:\.\$?\w+(?:' . $varBracket . ')*)*(?:@\w+)?(?:' . $dvarMath . '(?:' . $numConst . '|' . $dvarMathVar . ')*)?';
        $dvar = '\$' . $dvarGuts;

        $avar = '(?:' . $dvar . ')';
        $var = '(?:' . $avar . '|' . $qstr . ')';

        $objExt = '\->(?:\$?' . $dvarGuts . ')';
        $objRestrictedParam = '(?:'
            . '(?:' . $var . '|' . $numConst . ')(?:' . $objExt . '(?:\((?:(?:' . $var . '|' . $numConst . ')'
            . '(?:\s*,\s*(?:' . $var . '|' . $numConst . '))*)?\))?)*)';
        $objSingleParam = '(?:\w+|' . $objRestrictedParam . '(?:\s*,\s*(?:(?:\w+|' . $var . $objRestrictedParam . ')))*)';
        $objParams = '\((?:' . $objSingleParam . '(?:\s*,\s*' . $objSingleParam . ')*)?\)';
        $objStart = '(?:' . $dvar . '(?:' . $objExt . ')+)';
        $objCall = '(?:' . $objStart . '(?:' . $objParams . ')?(?:' . $dvarMath . '(?:' . $numConst . '|' . $dvarMathVar . ')*)?)';

        $mod = '(?:\|@?\w+(?::(?:\w+|' . $numConst . '|' . $objCall . '|' . $avar . '|' . $qstr . '))*)';
        $func = '[a-zA-Z_]\w*';
        $varRegexp = $var;

        $this->commandRegexp = '(?:' . $numConst . '|' . $objCall . '|' . $varRegexp . '|/?' . $func . ')';
        $this->modRegexp = $mod;
        // 注意：沿用旧编译器的非对称锚定语义 ^NUM|OBJCALL|VAR$ —— 即 (^NUM)|(OBJCALL)|(VAR$)，
        // 不可改写为 ^(?:...)$，否则分类结果会变（须与编译产物字节对齐）。
        $this->echoRegexp = $numConst . '|' . $objCall . '|' . $varRegexp;
    }

    /**
     * 是否为变量三元标签：{$a ? x : y} / {$a == 1 ? x : y}。
     *
     * @param string $content 去定界符后的标签内容
     * @return bool
     */
    public function isTernary($content)
    {
        return (bool) preg_match('~^(\$[\w\.\[\]\$]+(?:\s*(?:==|!=|<>|>=|<=|>|<)\s*(?:-?\d+(?:\.\d+)?|\$[\w\.]+))?)\s*\?\s*(.*?)\s*:\s*(.+)$~s', $content);
    }

    /**
     * 命令 / 修饰器链 / 参数 三段切分。
     *
     * @param string $content 去定界符后的标签内容
     * @return array|false array('command'=>, 'modifier'=>, 'args'=>)；不匹配返回 false
     */
    public function splitCommand($content)
    {
        if (!preg_match('~^(?:(' . $this->commandRegexp . ')(' . $this->modRegexp . '*))
                      (?:\s+(.*))?$
                    ~xs', $content, $match)) {
            return false;
        }

        return array(
            'command' => $match[1],
            'modifier' => isset($match[2]) ? $match[2] : '',
            'args' => isset($match[3]) ? $match[3] : '',
        );
    }

    /**
     * 命令是否为「变量 / 对象 / 数字常量」（直接输出形态）。
     *
     * @param string $command 命令段
     * @return bool
     */
    public function isEchoCommand($command)
    {
        return (bool) preg_match('~^' . $this->echoRegexp . '$~', $command);
    }
}
