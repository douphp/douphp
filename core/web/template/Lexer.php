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
 * 词法切分：把模板源切成有序 {@see Token} 序列（{@see TokenStream}）。
 *
 * Twig 式 cursor + 状态机单趟扫描（取代旧「fold + preg_match_all + preg_split」三趟）。
 * 在 DATA 态推进游标，遇左定界符时按优先级逐一判定：
 *   1) `{*...*}` 注释 → COMMENT；2) `{literal}...{/literal}` → LITERAL；
 *   3) `{php}...{/php}` → PHP；4) 普通 `{...}` → TAG（内容按 `\s*` 去边空白）。
 * 受保护区（注释/literal/php）整体吞掉其内的 `{`，无对应闭合则回退当普通标签处理。
 * 文本块内疑似 PHP 起止符转义为 echo 字面，杜绝注入。
 *
 * 输出 TokenStream 与旧实现逐字节对齐（渲染回归由 devtools/douview-list-tag-smoke.php 冒烟覆盖）。
 * 词法层允许小块 `/A` 锚定 regex（与 Twig Lexer 同级），不做整段表达式 regex lowering。
 */
class Lexer
{
    /**
     * 词法切分入口。
     *
     * @param string $source 模板源（已过 prefilter）
     * @param string $leftDelimiter 左定界符（默认 '{'）
     * @param string $rightDelimiter 右定界符（默认 '}'）
     * @return TokenStream
     */
    public function tokenize($source, $leftDelimiter, $rightDelimiter)
    {
        $ldq = preg_quote($leftDelimiter, '~');
        $rdq = preg_quote($rightDelimiter, '~');
        $ldLen = strlen($leftDelimiter);

        $commentOpen = $leftDelimiter . '*';
        $commentClose = '*' . $rightDelimiter;
        $literalOpenRe = "~{$ldq}\\s*literal\\s*{$rdq}~A";
        $literalCloseRe = "~{$ldq}\\s*/literal\\s*{$rdq}~";
        $phpOpenRe = "~{$ldq}\\s*php\\s*{$rdq}~A";
        $phpCloseRe = "~{$ldq}\\s*/php\\s*{$rdq}~";
        $tagRe = "~{$ldq}\\s*(.*?)\\s*{$rdq}~As";

        $tokens = array();
        $line = 1;
        $textBuf = '';
        $i = 0;
        $n = strlen($source);

        while ($i < $n) {
            if (substr($source, $i, $ldLen) !== $leftDelimiter) {
                $textBuf .= $source[$i];
                $i++;
                continue;
            }

            // 1) 注释 {*...*}
            if (substr($source, $i, $ldLen + 1) === $commentOpen) {
                $closePos = strpos($source, $commentClose, $i + $ldLen + 1);
                if ($closePos !== false) {
                    $bodyStart = $i + $ldLen + 1;
                    $body = substr($source, $bodyStart, $closePos - $bodyStart);
                    $regionEnd = $closePos + strlen($commentClose);
                    $line = $this->flushText($tokens, $textBuf, $line);
                    $textBuf = '';
                    $tokens[] = new Token(Token::COMMENT, $body, $line);
                    $line += substr_count(substr($source, $i, $regionEnd - $i), "\n");
                    $i = $regionEnd;
                    continue;
                }
            }

            // 2) {literal}...{/literal}
            if (preg_match($literalOpenRe, $source, $mOpen, 0, $i)) {
                $openLen = strlen($mOpen[0]);
                if (preg_match($literalCloseRe, $source, $mClose, PREG_OFFSET_CAPTURE, $i + $openLen)) {
                    $bodyStart = $i + $openLen;
                    $closeStart = $mClose[0][1];
                    $body = substr($source, $bodyStart, $closeStart - $bodyStart);
                    $regionEnd = $closeStart + strlen($mClose[0][0]);
                    $line = $this->flushText($tokens, $textBuf, $line);
                    $textBuf = '';
                    $tokens[] = new Token(Token::LITERAL, $body, $line);
                    $line += substr_count(substr($source, $i, $regionEnd - $i), "\n");
                    $i = $regionEnd;
                    continue;
                }
            }

            // 3) {php}...{/php}
            if (preg_match($phpOpenRe, $source, $mOpen, 0, $i)) {
                $openLen = strlen($mOpen[0]);
                if (preg_match($phpCloseRe, $source, $mClose, PREG_OFFSET_CAPTURE, $i + $openLen)) {
                    $bodyStart = $i + $openLen;
                    $closeStart = $mClose[0][1];
                    $body = substr($source, $bodyStart, $closeStart - $bodyStart);
                    $regionEnd = $closeStart + strlen($mClose[0][0]);
                    $line = $this->flushText($tokens, $textBuf, $line);
                    $textBuf = '';
                    $tokens[] = new Token(Token::PHP, $body, $line);
                    $line += substr_count(substr($source, $i, $regionEnd - $i), "\n");
                    $i = $regionEnd;
                    continue;
                }
            }

            // 4) 普通标签 {...}
            if (preg_match($tagRe, $source, $mTag, 0, $i)) {
                $full = $mTag[0];
                $content = $mTag[1];
                $line = $this->flushText($tokens, $textBuf, $line);
                $textBuf = '';
                $tokens[] = new Token(Token::TAG, $content, $line);
                $line += substr_count($content, "\n");
                $i += strlen($full);
                continue;
            }

            // 左定界符未构成任何标签 → 作普通文本
            $textBuf .= $source[$i];
            $i++;
        }

        $textBuf = $this->escapeInlinePhp($textBuf);
        $tokens[] = new Token(Token::TEXT, $textBuf, $line);

        return new TokenStream($tokens);
    }

    /**
     * 把累积文本块（经 PHP 注入转义）作为 TEXT Token 落入序列，并推进行号。
     *
     * @param Token[] $tokens 引用：Token 序列
     * @param string $textBuf 待落地的文本块
     * @param int $line 当前行号
     * @return int 推进后的行号
     */
    private function flushText(array &$tokens, $textBuf, $line)
    {
        $text = $this->escapeInlinePhp($textBuf);
        $tokens[] = new Token(Token::TEXT, $text, $line);

        // 行号按转义前原文推进：escapeInlinePhp 会为每处替换追加换行，不属于源行
        return $line + substr_count($textBuf, "\n");
    }

    /**
     * 文本块内疑似 PHP 起止符（<? ?> language=php）转义为 echo 字面，杜绝注入。
     *
     * @param string $text
     * @return string
     */
    private function escapeInlinePhp($text)
    {
        if (preg_match_all('~(<\?(?:\w+|=)?|\?>|language\s*=\s*[\"\']?\s*php\s*[\"\']?)~is', $text, $sp_match)) {
            $sp_match[1] = array_unique($sp_match[1]);
            usort($sp_match[1], array($this, 'sortByLengthDesc'));
            for ($curr_sp = 0, $for_max = count($sp_match[1]); $curr_sp < $for_max; $curr_sp++) {
                $text = str_replace($sp_match[1][$curr_sp], '%%%DOUVIEWSP' . $curr_sp . '%%%', $text);
            }
            for ($curr_sp = 0, $for_max = count($sp_match[1]); $curr_sp < $for_max; $curr_sp++) {
                $text = str_replace('%%%DOUVIEWSP' . $curr_sp . '%%%', '<?php echo \'' . str_replace("'", "\\'", $sp_match[1][$curr_sp]) . '\'; ?>' . "\n", $text);
            }
        }

        return $text;
    }

    /**
     * usort 比较器：按字符串长度降序（长串先替换，避免前缀误伤）。
     *
     * @param string $a
     * @param string $b
     * @return int
     */
    public function sortByLengthDesc($a, $b)
    {
        $la = strlen($a);
        $lb = strlen($b);
        if ($la === $lb) {
            return 0;
        }

        return $la < $lb ? 1 : -1;
    }
}
