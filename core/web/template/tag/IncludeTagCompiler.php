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
 * {@see \Dou\Core\Web\Template\Ast\NodeType::INCLUDE_} 标签编译器：
 * {include file=... 具名传参 assign=...} → $ctx->includeTemplate(...)。
 */
class IncludeTagCompiler implements TagCompilerInterface
{
    /**
     * @inheritDoc
     */
    public function compile(Node $node, TagCompileContext $ctx)
    {
        $attrs = $ctx->expr->parseAttrs($node->data['args']);

        if (empty($attrs['file'])) {
            $ctx->expr->syntaxError("missing 'file' attribute in include tag");
        }

        $includeFile = null;
        $assignVar = null;
        $argList = array();

        foreach ($attrs as $argName => $argValue) {
            if ($argName == 'file') {
                $includeFile = $argValue;
                continue;
            } elseif ($argName == 'assign') {
                $assignVar = $argValue;
                continue;
            }
            $argList[] = "'$argName' => $argValue";
        }

        $varsExpr = 'array(' . implode(', ', $argList) . ')';

        $output = '<?php ';
        if ($assignVar !== null) {
            $output .= "\$ctx->includeTemplate($includeFile, $varsExpr, $assignVar);";
        } else {
            $output .= "\$ctx->includeTemplate($includeFile, $varsExpr);";
        }
        $output .= ' ?>';

        $ctx->emit($output);
    }
}
