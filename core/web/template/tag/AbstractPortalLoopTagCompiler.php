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
 * Portal 数据块标签编译器基类：{list} / {category} 共用的「解析 → Portal 取数 → foreach 循环」骨架。
 *
 * 循环层参数与 {@see ForeachTagCompiler} 对齐：item / key / name / offset、@first/@last 等循环属性、
 * {listelse}/{categoryelse} 空态分支。差异在数据源：编译期固化 module 字面量后，运行期经 Portal
 * 白名单入口取数。offset 在取数后做 array_slice（与 foreach 一致）；limit 等其余属性进 Portal props
 *（{list} 的 limit 走 SQL LIMIT，语义不同于 foreach 的 slice limit）。
 *
 * 安全约束（编译期强制）：module 必须为字面量标识符（禁止运行时变量），确保模板无法把
 * 请求参数注入模块名；其余属性作为 props 表达式透传，由 Portal 侧做类型化与白名单校验。
 */
abstract class AbstractPortalLoopTagCompiler implements TagCompilerInterface
{
    /**
     * 标签名（错误信息用：list / category）。
     *
     * @return string
     */
    abstract protected function tagName();

    /**
     * Portal 静态入口方法名（listFor / categoryFor）。
     *
     * @return string
     */
    abstract protected function portalMethod();

    /**
     * 不进 props 的保留属性名集合（module / item / name 之外的标签级属性）。
     *
     * @return array<string, bool>
     */
    protected function extraReservedAttrs()
    {
        return array();
    }

    /**
     * 子类对属性做标签级校验 / 改写 props 的钩子；返回追加进 props 的「'key' => PHP表达式」片段。
     *
     * @param array $attrs parseAttrs 结果
     * @param TagCompileContext $ctx
     * @return array<int, string>
     */
    protected function buildExtraProps(array $attrs, TagCompileContext $ctx)
    {
        return array();
    }

    /**
     * @inheritDoc
     */
    public function compile(Node $node, TagCompileContext $ctx)
    {
        $ctx->emit($this->compileStart($node->data['args'], $ctx));
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
     * 编译块起始：Portal 取数 + 循环元数据 + foreach 打开。
     *
     * @param string $tagArgs
     * @param TagCompileContext $ctx
     * @return string
     */
    private function compileStart($tagArgs, TagCompileContext $ctx)
    {
        $tag = $this->tagName();
        $attrs = $ctx->expr->parseAttrs($tagArgs);

        if (empty($attrs['module'])) {
            $ctx->expr->syntaxError("$tag: missing 'module' attribute");
        }
        $module = $ctx->expr->dequote($attrs['module']);
        if (!$ctx->expr->isIdentifier($module)) {
            $ctx->expr->syntaxError("$tag: 'module' must be a literal module name (variables not allowed)");
        }

        if (empty($attrs['item'])) {
            $ctx->expr->syntaxError("$tag: missing 'item' attribute");
        }
        $item = $ctx->expr->dequote($attrs['item']);
        if (!$ctx->expr->isIdentifier($item)) {
            $ctx->expr->syntaxError("$tag: 'item' must be a variable name (literal string)");
        }

        if (isset($attrs['key'])) {
            $key = $ctx->expr->dequote($attrs['key']);
            if (!$ctx->expr->isIdentifier($key)) {
                $ctx->expr->syntaxError("$tag: 'key' must be a variable name (literal string)");
            }
            $keyPart = "\$ctx->vars['$key'] => ";
        } else {
            $keyPart = '';
        }

        if (isset($attrs['name'])) {
            $name = $ctx->expr->dequote($attrs['name']);
            if (!$ctx->expr->isIdentifier($name)) {
                $ctx->expr->syntaxError("$tag: 'name' must be a literal loop name");
            }
        } else {
            $name = '_auto_' . $ctx->foreachSeq++ . '_' . $item;
        }

        // offset 与 foreach 一致：编译期字面量，取数后 array_slice；不进 Portal props
        $offset = isset($attrs['offset']) ? intval($attrs['offset']) : 0;

        $reserved = array(
            'module' => true,
            'item' => true,
            'key' => true,
            'name' => true,
            'offset' => true,
        ) + $this->extraReservedAttrs();
        $propPairs = $this->buildExtraProps($attrs, $ctx);
        foreach ($attrs as $attrName => $attrExpr) {
            if (isset($reserved[$attrName])) {
                continue;
            }
            $propPairs[] = "'" . $attrName . "' => (" . $attrExpr . ")";
        }
        $propsExpr = 'array(' . implode(', ', $propPairs) . ')';

        $method = $this->portalMethod();

        $output = '<?php ';
        $output .= "\$_from = \\Dou\\Core\\Facade\\Portal::$method('$module', $propsExpr);\n";
        if ($offset > 0) {
            $output .= "\$_from = array_slice(\$_from, $offset);\n";
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
