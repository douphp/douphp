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
 * {@see \Dou\Core\Web\Template\Ast\NodeType::FOREACH_} 标签编译器：
 * {foreach from=... item=... key=... name=... limit=... offset=...} +
 * {foreachelse}；循环元数据写入 $ctx->loops，item/key 标识符校验走表达式层零 preg。
 */
class ForeachTagCompiler implements TagCompilerInterface
{
    /**
     * @inheritDoc
     */
    public function compile(Node $node, TagCompileContext $ctx)
    {
        $ctx->emit($this->compileForeachStart($node->data['args'], $ctx));
        $ctx->emitNodes($node->children);

        if ($node->hasElse) {
            $ctx->emit('<?php endforeach; else: ?>');
            $ctx->emitNodes($node->elseChildren);
            $ctx->emit('<?php endif; unset($_from); ?>');
        } else {
            $ctx->emit('<?php endforeach; endif; unset($_from); ?>');
        }
    }

    /**
     * 编译 {foreach} 起始为 PHP。
     *
     * @param string $tagArgs
     * @param TagCompileContext $ctx
     * @return string
     */
    private function compileForeachStart($tagArgs, TagCompileContext $ctx)
    {
        $attrs = $ctx->expr->parseAttrs($tagArgs);

        if (empty($attrs['from'])) {
            $ctx->expr->syntaxError("foreach: missing 'from' attribute");
        }
        $from = $attrs['from'];

        if (empty($attrs['item'])) {
            $ctx->expr->syntaxError("foreach: missing 'item' attribute");
        }
        $item = $ctx->expr->dequote($attrs['item']);
        if (!$ctx->expr->isIdentifier($item)) {
            $ctx->expr->syntaxError("foreach: 'item' must be a variable name (literal string)");
        }

        if (isset($attrs['key'])) {
            $key = $ctx->expr->dequote($attrs['key']);
            if (!$ctx->expr->isIdentifier($key)) {
                $ctx->expr->syntaxError("foreach: 'key' must be a variable name (literal string)");
            }
            $keyPart = "\$ctx->vars['$key'] => ";
        } else {
            $key = null;
            $keyPart = '';
        }

        if (isset($attrs['name'])) {
            $name = $ctx->expr->dequote($attrs['name']);
        } else {
            $name = null;
        }

        if (!isset($name)) {
            $name = '_auto_' . $ctx->foreachSeq++ . '_' . $item;
        }

        $limit = isset($attrs['limit']) ? intval($attrs['limit']) : null;
        $offset = isset($attrs['offset']) ? intval($attrs['offset']) : 0;

        $output = '<?php ';
        $output .= "\$_from = $from; if (!is_array(\$_from) && !is_object(\$_from)) { settype(\$_from, 'array'); }";

        if ($limit !== null || $offset > 0) {
            $output .= "\$_from = array_slice(\$_from, $offset" . ($limit !== null ? ", $limit" : '') . ");\n";
        }

        $output .= "\$ctx->loops['$name'] = array('total' => count(\$_from), 'iteration' => 0);\n";
        $output .= "\$ctx->loopVarmap['$item'] = '$name';\n";
        $output .= "if (\$ctx->loops['$name']['total'] > 0):\n";
        $output .= "    foreach (\$_from as $keyPart\$ctx->vars['$item']):\n";
        $output .= "        \$ctx->loops['$name']['iteration']++;\n";
        $output .= '?>';

        return $output;
    }
}
