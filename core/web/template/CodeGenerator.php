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
use Dou\Core\Web\Template\Expression\ExpressionCompiler;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 代码生成编排：遍历 AST（{@see Node}）按节点类型分发到 {@see TagCompilerRegistry} 注册的
 * {@see \Dou\Core\Web\Template\Contract\TagCompilerInterface}，产出标准 PHP；运行期载体为
 * {@see RenderContext}（$ctx-> 访问）。
 *
 * 两段式：
 *   1) 按指令出现顺序把每个节点交由对应 TagCompiler emit 为一段 PHP/字面（compiledTags[]），
 *      与扁平文本块（texts[]）一一对应；表达式经 {@see ExpressionCompiler} 零 preg 前端 lowering，
 *      {strip} 以哨兵 '{strip}' / '{/strip}' 标记。
 *   2) 复用与现网一致的空白后处理（{strip} 去空白、空标签吞行首换行、<? 防注入护栏、去尾换行），
 *      保证渲染输出与旧引擎字节对齐——此段空白语义字节敏感，集中保留在本类 {@see assemble()}。
 */
class CodeGenerator
{
    /** @var ExpressionCompiler */
    private $expr;
    /** @var TagCompilerRegistry 节点类型 → 标签编译器 */
    private $registry;

    /** @var bool 全局自动 HTML 转义 */
    private $escapeHtml;
    /** @var string 左定界符（{ldelim}） */
    private $leftDelimiter;
    /** @var string 右定界符（{rdelim}） */
    private $rightDelimiter;

    /** @var string 当前资源名（错误定位） */
    private $currentFile = null;
    /** @var array emit 期累积的指令片段序列 */
    private $tags = array();
    /** @var TagCompileContext|null 本次 generate 的编译上下文 */
    private $ctx = null;

    /**
     * @param ExpressionCompiler $expr 表达式编译器
     * @param TagCompilerRegistry $registry 标签编译器注册表
     * @param bool $escapeHtml 全局自动 HTML 转义
     * @param string $leftDelimiter 左定界符
     * @param string $rightDelimiter 右定界符
     */
    public function __construct(ExpressionCompiler $expr, TagCompilerRegistry $registry, $escapeHtml = false, $leftDelimiter = '{', $rightDelimiter = '}')
    {
        $this->expr = $expr;
        $this->registry = $registry;
        $this->escapeHtml = (bool) $escapeHtml;
        $this->leftDelimiter = $leftDelimiter;
        $this->rightDelimiter = $rightDelimiter;
    }

    /**
     * 生成编译产物 PHP。
     *
     * @param Node $document DOCUMENT 根节点
     * @param string[] $texts 扁平文本块（比指令数多一项）
     * @param string $resourceName 资源名（错误定位 + $smarty.template）
     * @return string
     */
    public function generate(Node $document, array $texts, $resourceName)
    {
        $this->currentFile = $resourceName;
        $this->tags = array();
        $this->ctx = new TagCompileContext($this, $this->expr, $this->escapeHtml, $this->leftDelimiter, $this->rightDelimiter);
        $this->ctx->currentFile = $resourceName;

        $this->emitNodes($document->children);

        return $this->assemble($this->tags, $texts);
    }

    /**
     * 顺序 emit 子节点序列（供 TagCompiler 递归块体）。
     *
     * @param Node[] $nodes
     * @return void
     */
    public function emitNodes(array $nodes)
    {
        foreach ($nodes as $node) {
            $this->emitNode($node);
        }
    }

    /**
     * 追加一段编译片段到指令序列（供 {@see TagCompileContext::emit()} 回写）。
     *
     * @param string $php
     * @return void
     */
    public function pushTag($php)
    {
        $this->tags[] = $php;
    }

    /**
     * emit 单个节点：按类型分发到注册表中的标签编译器。
     *
     * @param Node $node
     * @return void
     */
    private function emitNode(Node $node)
    {
        $this->ctx->setLine($node->line);

        $compiler = $this->registry->get($node->type);
        if ($compiler === null) {
            $this->expr->syntaxError("unregistered AST node type '{$node->type}'");
            return;
        }
        $compiler->compile($node, $this->ctx);
    }

    /**
     * 把 compiledTags[] 与 texts[] 装配为最终 PHP（{strip} 去空白 + 空标签吞行首换行 + <? 护栏 + 去尾换行）。
     *
     * @param string[] $compiled_tags 指令片段序列
     * @param string[] $text_blocks 文本块序列（比指令多一项）
     * @return string
     */
    private function assemble(array $compiled_tags, array $text_blocks)
    {
        // {strip}/{/strip} 之间的文本块去空白
        $strip = false;
        for ($i = 0, $for_max = count($compiled_tags); $i < $for_max; $i++) {
            if ($compiled_tags[$i] == '{strip}') {
                $compiled_tags[$i] = '';
                $strip = true;
                $text_blocks[$i + 1] = ltrim($text_blocks[$i + 1]);
            }
            if ($strip) {
                for ($j = $i + 1; $j < $for_max; $j++) {
                    $text_blocks[$j] = preg_replace('![\t ]*[\r\n]+[\t ]*!', '', $text_blocks[$j]);
                    if ($compiled_tags[$j] == '{/strip}') {
                        $text_blocks[$j] = rtrim($text_blocks[$j]);
                    }
                    $text_blocks[$j] = "<?php echo '" . strtr($text_blocks[$j], array("'" => "\\'", "\\" => "\\\\")) . "'; ?>";
                    if ($compiled_tags[$j] == '{/strip}') {
                        $compiled_tags[$j] = "\n";
                        $strip = false;
                        $i = $j;
                        break;
                    }
                }
            }
        }

        $compiled_content = '';
        $tag_guard = '%%%DOUVIEWOTG' . md5(uniqid(rand(), true)) . '%%%';

        for ($i = 0, $for_max = count($compiled_tags); $i < $for_max; $i++) {
            if ($compiled_tags[$i] == '') {
                $text_blocks[$i + 1] = preg_replace('~^(\r\n|\r|\n)~', '', $text_blocks[$i + 1]);
            }
            $text_blocks[$i] = str_replace('<?', $tag_guard, $text_blocks[$i]);
            $compiled_tags[$i] = str_replace('<?', $tag_guard, $compiled_tags[$i]);
            $compiled_content .= $text_blocks[$i] . $compiled_tags[$i];
        }
        $compiled_content .= str_replace('<?', $tag_guard, $text_blocks[$i]);

        $compiled_content = str_replace('<?', "<?php echo '<?' ?>\n", $compiled_content);
        $compiled_content = preg_replace("~(?<!')language\\s*=\\s*[\"\\']?\\s*php\\s*[\"\\']?~", "<?php echo 'language=php' ?>\n", $compiled_content);
        $compiled_content = str_replace($tag_guard, '<?', $compiled_content);

        if (strlen($compiled_content) && (substr($compiled_content, -1) == "\n")) {
            $compiled_content = substr($compiled_content, 0, -1);
        }

        return $compiled_content;
    }
}
