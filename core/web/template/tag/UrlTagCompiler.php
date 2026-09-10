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
 * {@see \Dou\Core\Web\Template\Ast\NodeType::URL} 标签编译器：把 {url link=... params=...}
 * 编译为全局 route() 调用（DouPHP 核心定制能力，须与现网 route() 行为一致）。
 */
class UrlTagCompiler implements TagCompilerInterface
{
    /**
     * @inheritDoc
     */
    public function compile(Node $node, TagCompileContext $ctx)
    {
        // 裸词 flag 形态 {url ... nofilter}（与 echo 标签 {$x nofilter} 同语法）先行剥离，
        // 属性形态 nofilter=true/false 留给 parseAttrs 按值判定
        list($args, $nofilter) = $this->extractNofilterFlag($node->data['args']);
        $attrs = $ctx->expr->parseAttrs($args);

        if (empty($attrs['link'])) {
            $ctx->expr->syntaxError("missing 'link' attribute in url tag");
        }

        $linkExpr = $attrs['link'];
        $pageExpr = isset($attrs['page']) ? $attrs['page'] : null;
        $paramsAttr = isset($attrs['params']) ? $attrs['params'] : null;
        $optionsAttr = isset($attrs['options']) ? $attrs['options'] : null;

        if (isset($attrs['nofilter'])) {
            $nofilter = $nofilter || $attrs['nofilter'] === 'true';
        }

        $reserved = array('link' => true, 'params' => true, 'page' => true, 'options' => true, 'nofilter' => true);
        $inlinePairs = array();
        foreach ($attrs as $attrName => $attrExpr) {
            if (isset($reserved[$attrName])) {
                continue;
            }
            $inlinePairs[] = "'" . $attrName . "' => (" . $attrExpr . ")";
        }

        $paramsExpr = ($paramsAttr !== null) ? $paramsAttr : 'array()';
        if (!empty($inlinePairs)) {
            $paramsExpr = 'array_merge((array)(' . $paramsExpr . '), array(' . implode(', ', $inlinePairs) . '))';
        }

        $output = '<?php ';
        $output .= "\$_dou_url_opts = " . ($optionsAttr !== null ? $optionsAttr : 'array()') . ";";
        if ($pageExpr !== null) {
            $output .= "\$_dou_url_opts['page'] = $pageExpr;";
        }
        $output .= "\$_dou_url_result = route((string)($linkExpr), $paramsExpr, \$_dou_url_opts);";

        if ($ctx->escapeHtml && !$nofilter) {
            $output .= "echo htmlspecialchars((string)(\$_dou_url_result), ENT_QUOTES, 'UTF-8');";
        } else {
            $output .= "echo \$_dou_url_result;";
        }

        $output .= ' ?>' . $ctx->additionalNewline;

        $ctx->emit($output);
    }

    /**
     * 从属性串中剥离「独立裸词」形态的 nofilter（引号外、词边界、不与 `=` 相邻）。
     *
     * @param string $args 标签属性串
     * @return array array(剥离后的属性串, 是否命中裸词 nofilter)
     */
    private function extractNofilterFlag($args)
    {
        $args = (string) $args;
        $n = strlen($args);
        $inq = '';
        for ($i = 0; $i < $n; $i++) {
            $c = $args[$i];
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
            if ($c !== 'n' || substr($args, $i, 8) !== 'nofilter') {
                continue;
            }
            // 词边界
            $before = ($i > 0) ? $args[$i - 1] : '';
            $after = ($i + 8 < $n) ? $args[$i + 8] : '';
            if ($this->isWordChar($before) || $this->isWordChar($after)) {
                $i += 7;
                continue;
            }
            // 与 `=` 相邻（跳过空白）视为属性形态，留给 parseAttrs
            $b = $i - 1;
            while ($b >= 0 && ctype_space($args[$b])) {
                $b--;
            }
            $a = $i + 8;
            while ($a < $n && ctype_space($args[$a])) {
                $a++;
            }
            if (($b >= 0 && $args[$b] === '=') || ($a < $n && $args[$a] === '=')) {
                $i += 7;
                continue;
            }

            return array(substr($args, 0, $i) . substr($args, $i + 8), true);
        }

        return array($args, false);
    }

    /**
     * 是否词字符 [A-Za-z0-9_]（空字符串视为非词字符）。
     *
     * @param string $c
     * @return bool
     */
    private function isWordChar($c)
    {
        return $c !== '' && ($c === '_' || ctype_alnum($c));
    }
}
