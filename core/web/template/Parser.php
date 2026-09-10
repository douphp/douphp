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

use Dou\Core\Web\Template\Ast\Node;
use Dou\Core\Web\Template\Ast\NodeType;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 语法分析：把 {@see TokenStream} 构建为嵌套 AST（{@see Node} 树），并校验控制结构配对。
 *
 * 仅负责「结构」：标签分类、块嵌套（if/foreach/list/category/strip）配对、文本块顺序收集；
 * 表达式 lowering 推迟到 {@see CodeGenerator}。文本块以扁平数组（{@see getTexts()}）按位置保留，
 * 与每个指令 Token 一一对应，供 CodeGenerator 还原空白处理口径。
 */
class Parser
{
    /** @var array 循环块标签：open 关键字 => NodeType（{xxx}/{xxxelse}/{/xxx} 三态同构） */
    private static $loopTags = array(
        'foreach' => NodeType::FOREACH_,
        'list' => NodeType::LIST_,
        'category' => NodeType::CATEGORY,
    );

    /** @var TagClassifier 标签形态判定（三元 / 命令切分 / echo 判定），不在此 emit PHP */
    private $classifier;

    /** @var string 当前资源名（错误定位） */
    private $currentFile = null;
    /** @var int 当前行号（错误定位） */
    private $currentLineNo = 1;

    /** @var string[] 扁平文本块序列（比指令 Token 多一项） */
    private $texts = array();

    /**
     * @param TagClassifier $classifier 标签分类器
     */
    public function __construct(TagClassifier $classifier)
    {
        $this->classifier = $classifier;
    }

    /**
     * 解析 Token 流为 AST 文档根。
     *
     * @param TokenStream $stream
     * @param string $resourceName 资源名（错误定位）
     * @return Node DOCUMENT 根节点
     */
    public function parse(TokenStream $stream, $resourceName)
    {
        $this->currentFile = $resourceName;
        $this->currentLineNo = 1;
        $this->texts = array();

        $document = new Node(NodeType::DOCUMENT, 1);
        $stack = array($document);

        while ($stream->valid()) {
            $token = $stream->next();
            $this->currentLineNo = $token->line;

            switch ($token->type) {
                case Token::TEXT:
                    $this->texts[] = $token->value;
                    break;

                case Token::COMMENT:
                    $this->appendChild($stack, new Node(NodeType::COMMENT, $token->line));
                    break;

                case Token::LITERAL:
                    $literal = new Node(NodeType::LITERAL, $token->line);
                    $literal->data['text'] = $token->value;
                    $this->appendChild($stack, $literal);
                    break;

                case Token::PHP:
                    $this->syntaxError('php tags not permitted');
                    break;

                case Token::TAG:
                    $this->parseTag($token, $stack);
                    break;
            }
        }

        if (count($stack) > 1) {
            $open = $stack[count($stack) - 1];
            $this->currentLineNo = $open->line;
            $this->syntaxError('unclosed tag {' . $this->openKeyword($open) . '} (opened line ' . $open->line . ').');
        }

        return $document;
    }

    /**
     * 取扁平文本块序列（parse 之后调用）。
     *
     * @return string[]
     */
    public function getTexts()
    {
        return $this->texts;
    }

    /**
     * 分类并处理单个 {tag}。
     *
     * @param Token $token
     * @param Node[] $stack 引用：容器栈
     * @return void
     */
    private function parseTag(Token $token, array &$stack)
    {
        $content = $token->value;
        $line = $token->line;

        // 变量三元 {$a ? x : y} / {$a == 1 ? x : y}
        if ($this->classifier->isTernary($content)) {
            $node = new Node(NodeType::TERNARY, $line);
            $node->data['raw'] = $content;
            $this->appendChild($stack, $node);
            return;
        }

        // 命令 / 修饰器 / 参数 三段切分
        $parts = $this->classifier->splitCommand($content);
        if ($parts === false) {
            $this->syntaxError("unrecognized tag: $content");
            return;
        }

        $command = $parts['command'];
        $modifier = $parts['modifier'];
        $args = $parts['args'];

        // 变量 / 对象 / 数字常量直接输出
        if ($this->classifier->isEchoCommand($command)) {
            $node = new Node(NodeType::ECHO_, $line);
            $node->data['command'] = $command;
            $node->data['modifier'] = $modifier;
            $node->data['args'] = $args;
            $this->appendChild($stack, $node);
            return;
        }

        $cmd = strtolower($command);

        // 循环块三态（{foreach}/{list}/{category} 及各自 else / 闭合）表驱动统一处理
        if ($this->parseLoopTag($cmd, $args, $line, $stack)) {
            return;
        }

        switch ($cmd) {
            case 'if':
                $node = new Node(NodeType::IF_, $line);
                $node->branches[] = array('keyword' => 'if', 'args' => $args, 'line' => $line, 'children' => array());
                $this->appendChild($stack, $node);
                $stack[] = $node;
                break;

            case 'elseif':
                $top = $this->requireTop($stack, NodeType::IF_, 'unexpected {elseif}');
                $top->branches[] = array('keyword' => 'elseif', 'args' => $args, 'line' => $line, 'children' => array());
                break;

            case 'else':
                $top = $this->requireTop($stack, NodeType::IF_, 'unexpected {else}');
                $top->branches[] = array('keyword' => 'else', 'args' => '', 'line' => $line, 'children' => array());
                break;

            case '/if':
                $this->requireTop($stack, NodeType::IF_, 'mismatched tag {/if}.');
                array_pop($stack);
                break;

            case 'strip':
                $node = new Node(NodeType::STRIP, $line);
                $this->appendChild($stack, $node);
                $stack[] = $node;
                break;

            case '/strip':
                $this->requireTop($stack, NodeType::STRIP, 'mismatched tag {/strip}.');
                array_pop($stack);
                break;

            case 'break':
                $this->requireLoopContext($stack, 'break');
                $this->appendChild($stack, new Node(NodeType::BREAK_, $line));
                break;

            case 'continue':
                $this->requireLoopContext($stack, 'continue');
                $this->appendChild($stack, new Node(NodeType::CONTINUE_, $line));
                break;

            case 'ldelim':
                $node = new Node(NodeType::DELIM, $line);
                $node->data['which'] = 'l';
                $this->appendChild($stack, $node);
                break;

            case 'rdelim':
                $node = new Node(NodeType::DELIM, $line);
                $node->data['which'] = 'r';
                $this->appendChild($stack, $node);
                break;

            case 'include':
                $node = new Node(NodeType::INCLUDE_, $line);
                $node->data['args'] = $args;
                $this->appendChild($stack, $node);
                break;

            case 'assign':
                $node = new Node(NodeType::ASSIGN, $line);
                $node->data['args'] = $args;
                $this->appendChild($stack, $node);
                break;

            case 'url':
                $node = new Node(NodeType::URL, $line);
                $node->data['args'] = $args;
                $this->appendChild($stack, $node);
                break;

            case 'php':
                $this->syntaxError('php tags not permitted');
                break;

            default:
                $this->syntaxError("unrecognized tag '$command'");
                break;
        }
    }

    /**
     * 把节点追加到栈顶容器对应的子节点列表。
     *
     * @param Node[] $stack 引用：容器栈
     * @param Node $node
     * @return void
     */
    private function appendChild(array &$stack, Node $node)
    {
        $top = $stack[count($stack) - 1];

        switch ($top->type) {
            case NodeType::IF_:
                $li = count($top->branches) - 1;
                $top->branches[$li]['children'][] = $node;
                break;

            case NodeType::FOREACH_:
            case NodeType::LIST_:
            case NodeType::CATEGORY:
                if ($top->hasElse) {
                    $top->elseChildren[] = $node;
                } else {
                    $top->children[] = $node;
                }
                break;

            default:
                $top->children[] = $node;
                break;
        }
    }

    /**
     * 尝试按循环块三态处理命令：open（入栈）/ else（置 hasElse）/ close（校验并出栈）。
     *
     * @param string $cmd 小写命令
     * @param string $args 标签参数串
     * @param int $line 行号
     * @param Node[] $stack 引用：容器栈
     * @return bool 是否已作为循环块标签处理
     */
    private function parseLoopTag($cmd, $args, $line, array &$stack)
    {
        if (isset(self::$loopTags[$cmd])) {
            $node = new Node(self::$loopTags[$cmd], $line);
            $node->data['args'] = $args;
            $this->appendChild($stack, $node);
            $stack[] = $node;
            return true;
        }

        if (substr($cmd, -4) === 'else' && isset(self::$loopTags[substr($cmd, 0, -4)])) {
            $top = $this->requireTop($stack, self::$loopTags[substr($cmd, 0, -4)], 'unexpected {' . $cmd . '}');
            $top->hasElse = true;
            return true;
        }

        if (substr($cmd, 0, 1) === '/' && isset(self::$loopTags[substr($cmd, 1)])) {
            $this->requireTop($stack, self::$loopTags[substr($cmd, 1)], 'mismatched tag {' . $cmd . '}.');
            array_pop($stack);
            return true;
        }

        return false;
    }

    /**
     * 断言当前位置处于某个循环块的「本体」内（{break}/{continue} 编译期校验）。
     *
     * else 段（{foreachelse} 等）编译后位于 endforeach 之后，不构成循环上下文，
     * 继续向外层找；全程无命中则编译期报错，避免产出运行期 fatal 的 break/continue。
     *
     * @param Node[] $stack 容器栈
     * @param string $tag 标签名（错误信息用）
     * @return void
     */
    private function requireLoopContext(array $stack, $tag)
    {
        for ($i = count($stack) - 1; $i >= 1; $i--) {
            $node = $stack[$i];
            if (in_array($node->type, self::$loopTags, true) && !$node->hasElse) {
                return;
            }
        }

        $this->syntaxError('{' . $tag . '} outside of loop');
    }

    /**
     * 断言栈顶为指定类型，否则报错；返回栈顶节点。
     *
     * @param Node[] $stack
     * @param string $type 期望的栈顶 NodeType
     * @param string $error 不匹配时的错误信息
     * @return Node
     */
    private function requireTop(array $stack, $type, $error)
    {
        $top = $stack[count($stack) - 1];
        if ($top->type !== $type) {
            $this->syntaxError($error);
        }

        return $top;
    }

    /**
     * 取未闭合块的开标签关键字（错误信息用）。
     *
     * @param Node $node
     * @return string
     */
    private function openKeyword(Node $node)
    {
        switch ($node->type) {
            case NodeType::IF_:
                return 'if';
            case NodeType::FOREACH_:
                return 'foreach';
            case NodeType::LIST_:
                return 'list';
            case NodeType::CATEGORY:
                return 'category';
            case NodeType::STRIP:
                return 'strip';
            default:
                return $node->type;
        }
    }

    /**
     * 编译期语法错误：抛出异常中止编译。
     *
     * @param string $error_msg
     * @return void
     * @throws \RuntimeException
     */
    private function syntaxError($error_msg)
    {
        throw new \RuntimeException('DouView syntax error: ' . $error_msg
            . ' [in ' . $this->currentFile . ' line ' . $this->currentLineNo . ']');
    }
}
