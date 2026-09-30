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

namespace Dou\Core\Web\Template\Prefilter;

use Dou\Core\Web\Template\Contract\PrefilterContext;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台模板预过滤（静态方法供 DouView::registerPrefilter 注册）
 */
class AdminPrefilter
{
    /**
     * 后台：模板目录为 admin/view/，编译后相对站点根访问需带 view/（css、js、images）；还原注释中的标签
     *
     * @param string $source 模板源码
     * @param PrefilterContext $engine 编译前上下文（仅读 assign）
     * @return string
     */
    public static function apply($source, PrefilterContext $engine)
    {
        $source = preg_replace('/^<meta\shttp-equiv=["|\']Content-Type["|\']\scontent=["|\']text\/html;\scharset=(?:.*?)["|\'][^>]*?>\r?\n?/i', '', $source);
        // 静态资源改为绝对地址（{$admin_url} 渲染期解析，含 host），后台深路径（伪静态）下不受相对基准目录影响。
        $source = preg_replace('/href\s*=\s*(["\'])css\//i', 'href=$1{$admin_url}view/css/', $source);
        $source = preg_replace('/src\s*=\s*(["\'])js\//i', 'src=$1{$admin_url}view/js/', $source);
        $source = preg_replace('/src\s*=\s*(["\'])images\//i', 'src=$1{$admin_url}view/images/', $source);
        // 模板内硬编码的 href / action 裸 index.php?route= 链接绝对化前缀（深路径下仍可用；
        // 仅限 href/action 属性，避免误伤 {url ... back='index.php?route=...'} 这类标签参数）。
        $source = preg_replace('/(href|action)\s*=\s*(["\'])index\.php\?route=/i', '$1=$2{$admin_url}index.php?route=', $source);
        // 还原注释中的模板标签：注释体内含完整 {..} 标签时去掉 <!-- --> 包裹并激活其中全部标签
        // （如 {foreach}...{/foreach} 并存时须整体激活，只保留首个标签会丢闭合配对报 mismatched tag）；
        // 文本仅保留在成对块内部（如 <!-- {if !$first}、{/if} --> 的「、」），
        // 成对结构之外的说明文字（如 <!-- {if $rec eq 'default'} 起始页 --> 的「起始页」）丢弃，
        // 与旧 remove_html_comments 过滤器口径一致。
        $source = preg_replace_callback('/<!--(.*?)-->/', function ($m) {
            $restored = self::restoreCommentTags($m[1]);

            return $restored === null ? $m[0] : $restored;
        }, $source);

        return $source;
    }

    /**
     * 还原单条注释体内的模板标签（与 FrontPrefilter::restoreCommentTags() 逐字一致，改动须同步）。
     *
     * @param string $body 注释体（<!-- 与 --> 之间的内容，不跨行）
     * @return string|null 无完整 {..} 标签时返回 null（调用方保留原注释）
     */
    private static function restoreCommentTags($body)
    {
        if (!preg_match_all('/\{[^{}]*\}/', $body, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        // 块标签全集：Parser 入栈容器（if/foreach/list/category/strip）+ Lexer 保护区（literal）
        static $openKeywords = array('if', 'foreach', 'list', 'category', 'strip', 'literal');

        $tags = array();
        $stack = array();
        $ranges = array(); // 成对块内部区间 [开标签结束, 闭标签开始)

        foreach ($matches[0] as $match) {
            $tag = $match[0];
            $start = $match[1];
            $end = $start + strlen($tag);
            $tags[] = array($start, $end);

            $command = strtolower(preg_replace('/\s.*$/', '', trim(substr($tag, 1, -1))));
            if (in_array($command, $openKeywords, true)) {
                $stack[] = array($command, $end);
            } elseif (substr($command, 0, 1) === '/' && in_array(substr($command, 1), $openKeywords, true)) {
                if ($stack && $stack[count($stack) - 1][0] === substr($command, 1)) {
                    $pair = array_pop($stack);
                    $ranges[] = array($pair[1], $start);
                }
            }
        }

        $result = '';
        $cursor = 0;
        foreach ($tags as $position) {
            if (self::insideCommentRange($cursor, $position[0], $ranges)) {
                $result .= substr($body, $cursor, $position[0] - $cursor);
            }
            $result .= substr($body, $position[0], $position[1] - $position[0]);
            $cursor = $position[1];
        }
        if (self::insideCommentRange($cursor, strlen($body), $ranges)) {
            $result .= substr($body, $cursor);
        }

        return $result;
    }

    /**
     * 判断 [start, end) 是否完全落在任一成对块区间内。
     *
     * @param int $start 区间起偏移
     * @param int $end 区间止偏移
     * @param array $ranges 成对块区间列表
     * @return bool
     */
    private static function insideCommentRange($start, $end, array $ranges)
    {
        foreach ($ranges as $range) {
            if ($start >= $range[0] && $end <= $range[1]) {
                return true;
            }
        }

        return false;
    }
}
