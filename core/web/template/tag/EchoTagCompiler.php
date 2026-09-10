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

namespace Dou\Core\Web\Template\Tag;

use Dou\Core\Web\Template\Ast\Node;
use Dou\Core\Web\Template\Ast\NodeType;
use Dou\Core\Web\Template\Contract\TagCompilerInterface;
use Dou\Core\Web\Template\TagCompileContext;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * {@see NodeType::ECHO_} 变量/对象/数字输出（{$x|mod}）与 {@see NodeType::TERNARY}
 * 变量三元（{$a ? x : y}）编译器：表达式 / 修饰器 / nofilter / 三元解析全部委托
 * 表达式层零 preg 前端，本类只负责输出策略（全局转义 + 追加换行）。
 */
class EchoTagCompiler implements TagCompilerInterface
{
    /**
     * @inheritDoc
     */
    public function compile(Node $node, TagCompileContext $ctx)
    {
        if ($node->type === NodeType::TERNARY) {
            $this->compileTernary($node, $ctx);
            return;
        }

        $command = $node->data['command'];
        $modifier = $node->data['modifier'];
        $args = $node->data['args'];

        $nofilter = ($args !== '' && $ctx->expr->hasNofilterFlag($args));
        $return = $ctx->expr->parseVarProps($command . $modifier, $nofilter);
        $hasHtmlEscape = ($modifier !== '' && $ctx->expr->hasHtmlEscapeModifier($modifier));
        if ($ctx->escapeHtml && !$nofilter && !$hasHtmlEscape) {
            $return = "htmlspecialchars((string)(" . $return . "), ENT_QUOTES, 'UTF-8')";
        }

        $ctx->emit("<?php echo $return; ?>" . $ctx->additionalNewline);
    }

    /**
     * emit 变量三元。
     *
     * @param Node $node
     * @param TagCompileContext $ctx
     * @return void
     */
    private function compileTernary(Node $node, TagCompileContext $ctx)
    {
        $ternary = $ctx->expr->compileTernary($node->data['raw']);
        $expr = $ternary['expr'];
        if ($ctx->escapeHtml && !$ternary['nofilter']) {
            $expr = "htmlspecialchars((string)(" . $expr . "), ENT_QUOTES, 'UTF-8')";
        }

        $ctx->emit("<?php echo $expr; ?>" . $ctx->additionalNewline);
    }
}
