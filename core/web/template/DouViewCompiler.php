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

use Dou\Core\Web\Template\Ast\NodeType;
use Dou\Core\Web\Template\Expression\ExpressionCompiler;
use Dou\Core\Web\Template\Tag\AssignTagCompiler;
use Dou\Core\Web\Template\Tag\BreakTagCompiler;
use Dou\Core\Web\Template\Tag\CategoryTagCompiler;
use Dou\Core\Web\Template\Tag\CommentTagCompiler;
use Dou\Core\Web\Template\Tag\ContinueTagCompiler;
use Dou\Core\Web\Template\Tag\DelimTagCompiler;
use Dou\Core\Web\Template\Tag\EchoTagCompiler;
use Dou\Core\Web\Template\Tag\ForeachTagCompiler;
use Dou\Core\Web\Template\Tag\IfTagCompiler;
use Dou\Core\Web\Template\Tag\IncludeTagCompiler;
use Dou\Core\Web\Template\Tag\ListTagCompiler;
use Dou\Core\Web\Template\Tag\LiteralTagCompiler;
use Dou\Core\Web\Template\Tag\PhpTagCompiler;
use Dou\Core\Web\Template\Tag\StripTagCompiler;
use Dou\Core\Web\Template\Tag\UrlTagCompiler;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * DouView 模板编译器（对标 Laravel BladeCompiler）：Lexer → Parser → CodeGenerator 主编译入口。
 *
 * 仅负责「模板源 → PHP 字符串」；Prefilter 与编译缓存由 {@see DouView} 承担。
 */
class DouViewCompiler
{
    /** @var bool 全局自动 HTML 转义 */
    private $escapeHtml;
    /** @var string 左定界符 */
    private $leftDelimiter;
    /** @var string 右定界符 */
    private $rightDelimiter;

    /** @var Lexer|null */
    private $lexer = null;
    /** @var Parser|null */
    private $parser = null;
    /** @var TagClassifier|null */
    private $tagClassifier = null;
    /** @var ExpressionCompiler|null */
    private $expressionCompiler = null;
    /** @var TagCompilerRegistry|null */
    private $tagCompilerRegistry = null;
    /** @var CodeGenerator|null */
    private $codeGenerator = null;

    /**
     * @param bool $escapeHtml 全局自动 HTML 转义
     * @param string $leftDelimiter 左定界符
     * @param string $rightDelimiter 右定界符
     */
    public function __construct($escapeHtml = false, $leftDelimiter = '{', $rightDelimiter = '}')
    {
        $this->escapeHtml = (bool) $escapeHtml;
        $this->leftDelimiter = $leftDelimiter;
        $this->rightDelimiter = $rightDelimiter;
    }

    /**
     * 同步全局转义开关（丢弃已缓存的 CodeGenerator 实例）。
     *
     * @param bool $enable
     * @return void
     */
    public function setEscapeHtml($enable)
    {
        $this->escapeHtml = (bool) $enable;
        $this->codeGenerator = null;
    }

    /**
     * 编译模板源为 PHP（Lexer → Parser → CodeGenerator）。
     *
     * @param string $resourceName 资源名（错误定位 + $smarty.template）
     * @param string $source 已 Prefilter 的模板源
     * @return string
     */
    public function compile($resourceName, $source)
    {
        $stream = $this->getLexer()->tokenize($source, $this->leftDelimiter, $this->rightDelimiter);
        $parser = $this->getParser();
        $document = $parser->parse($stream, $resourceName);

        return $this->getCodeGenerator()->generate($document, $parser->getTexts(), $resourceName);
    }

    /**
     * @return Lexer
     */
    private function getLexer()
    {
        if ($this->lexer === null) {
            $this->lexer = new Lexer();
        }

        return $this->lexer;
    }

    /**
     * @return Parser
     */
    private function getParser()
    {
        if ($this->parser === null) {
            $this->parser = new Parser($this->getTagClassifier());
        }

        return $this->parser;
    }

    /**
     * @return TagClassifier
     */
    private function getTagClassifier()
    {
        if ($this->tagClassifier === null) {
            $this->tagClassifier = new TagClassifier();
        }

        return $this->tagClassifier;
    }

    /**
     * @return CodeGenerator
     */
    private function getCodeGenerator()
    {
        if ($this->codeGenerator === null) {
            $this->codeGenerator = new CodeGenerator(
                $this->getExpressionCompiler(),
                $this->getTagCompilerRegistry(),
                $this->escapeHtml,
                $this->leftDelimiter,
                $this->rightDelimiter
            );
        }

        return $this->codeGenerator;
    }

    /**
     * @return ExpressionCompiler
     */
    private function getExpressionCompiler()
    {
        if ($this->expressionCompiler === null) {
            $this->expressionCompiler = new ExpressionCompiler($this->leftDelimiter, $this->rightDelimiter);
        }

        return $this->expressionCompiler;
    }

    /**
     * 构造并填充内置标签编译器注册表（对标 {@see FilterRegistry} 装配模式）。
     *
     * @return TagCompilerRegistry
     */
    private function getTagCompilerRegistry()
    {
        if ($this->tagCompilerRegistry === null) {
            $registry = new TagCompilerRegistry();

            $echo = new EchoTagCompiler();
            $registry->register(NodeType::ECHO_, $echo);
            $registry->register(NodeType::TERNARY, $echo);
            $registry->register(NodeType::URL, new UrlTagCompiler());
            $registry->register(NodeType::INCLUDE_, new IncludeTagCompiler());
            $registry->register(NodeType::ASSIGN, new AssignTagCompiler());
            $registry->register(NodeType::IF_, new IfTagCompiler());
            $registry->register(NodeType::FOREACH_, new ForeachTagCompiler());
            $registry->register(NodeType::LIST_, new ListTagCompiler());
            $registry->register(NodeType::CATEGORY, new CategoryTagCompiler());
            $registry->register(NodeType::STRIP, new StripTagCompiler());
            $registry->register(NodeType::LITERAL, new LiteralTagCompiler());
            $registry->register(NodeType::COMMENT, new CommentTagCompiler());
            $registry->register(NodeType::PHP, new PhpTagCompiler());
            $registry->register(NodeType::DELIM, new DelimTagCompiler());
            $registry->register(NodeType::BREAK_, new BreakTagCompiler());
            $registry->register(NodeType::CONTINUE_, new ContinueTagCompiler());

            $this->tagCompilerRegistry = $registry;
        }

        return $this->tagCompilerRegistry;
    }
}
