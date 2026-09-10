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
use Dou\Core\Web\Template\Contract\TagCompilerInterface;
use Dou\Core\Web\Template\TagCompileContext;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * {@see \Dou\Core\Web\Template\Ast\NodeType::IF_} 标签编译器：
 * 按 Token 顺序逐分支 emit {if}/{elseif}/{else}，条件 lowering 委托表达式层。
 */
class IfTagCompiler implements TagCompilerInterface
{
    /**
     * @inheritDoc
     */
    public function compile(Node $node, TagCompileContext $ctx)
    {
        foreach ($node->branches as $branch) {
            $ctx->setLine($branch['line']);
            switch ($branch['keyword']) {
                case 'if':
                    $ctx->emit($ctx->expr->compileIfCondition($branch['args'], false));
                    break;
                case 'elseif':
                    $ctx->emit($ctx->expr->compileIfCondition($branch['args'], true));
                    break;
                case 'else':
                    $ctx->emit('<?php else: ?>');
                    break;
            }
            $ctx->emitNodes($branch['children']);
        }
        $ctx->emit('<?php endif; ?>');
    }
}
