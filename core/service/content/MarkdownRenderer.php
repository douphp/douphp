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

namespace Dou\Core\Service\Content;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Vendor\Parsedown\Parsedown;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * Markdown 渲染读模型：纯文本 → HTML，无任何视图副作用。
 *
 * 行为约定：
 * - 不向输出拼接 `<link href="...content.css">`；正文样式统一由主题 common.css 的
 *   「Content 正文」区块提供（content.css 已合并进 `css/common.css`）。
 * - 按内容本身自动判定格式：HTML 原样返回；否则视为 Markdown 转 HTML。
 * - 例外：后台 vditor 编辑表单直接返回原文（Markdown 源码回填，存量 HTML 由 init.js 转 Markdown）。
 */
class MarkdownRenderer extends BaseService
{
    /**
     * 将 Markdown 文本渲染为 HTML；HTML 内容原样返回。
     *
     * 判定规则：
     * - 后台 vditor 编辑表单：返回原文（供编辑器回填，HTML→Markdown 由前端 html2md 完成）。
     * - 其余场景：命中 HTML 标签即视为 HTML 原样返回；
     *   否则视为 Markdown，经 Parsedown 转 HTML。
     *
     * @param string|null $content 原始内容
     * @return string
     */
    public function toHtml($content = '')
    {
        $content = $content ? $content : '';

        // 后台 Vditor 编辑表单：回填 Markdown 源码（存量 HTML 交给 init.js 的 html2md 转换）
        if (defined('IS_ADMIN') && Config::get('site.editor', '') == 'vditor') {
            return $content;
        }

        // 非 HTML 即视为 Markdown（vditor 存 Markdown，ueditor 存 HTML），转 HTML 输出。
        // 不按 containsMarkdownSyntax 反推：HTML→Markdown 转换后的纯文本正文
        // （如 <p>a<br/><br/>b</p> → "a\n\nb"）不含 # / 列表等标记，会被漏判而丢失段落换行。
        if (!$this->looksLikeHtml($content)) {
            $parsedown = new Parsedown();
            // safe mode：转义 Markdown 中的内联 HTML 并过滤 javascript: 等危险链接协议。
            // 代价是正文里手写的内联 HTML 会按纯文本呈现。
            $parsedown->setSafeMode(true);
            $content = $parsedown->text($content);
        }

        return $content;
    }

    /**
     * 保存端格式与过滤决策：按当前编辑器配置处理待入库正文。
     *
     * - vditor：原样保存 Markdown 源码（HTML 过滤会破坏 Markdown 结构）。
     * - ueditor：富文本提交为 HTML，走 XSS 过滤。
     *
     * @param string|null $content 提交的正文
     * @return string
     */
    public function toStore($content)
    {
        $content = $content ? (string) $content : '';

        if (Config::get('site.editor', '') == 'vditor') {
            return $content;
        }

        $xss = xss();

        return $xss !== null ? $xss->content($content) : $content;
    }

    /**
     * 内容是否为 HTML 片段（防止 Markdown 宽泛模式误判 HTML）。
     *
     * 先剔除代码块 / 行内代码再探测，避免 Markdown 正文里的 HTML 示例（如文档中的代码示例）
     * 被误判为 HTML 内容而跳过 Markdown 渲染。
     *
     * @param string $content
     * @return bool
     */
    private function looksLikeHtml($content)
    {
        $probe = preg_replace('/```.*?```|~~~.*?~~~|`[^`]*`/s', '', (string) $content);

        return (bool) preg_match('/<\/?[a-z][a-z0-9]*(?:\s[^>]*)?>/i', $probe);
    }
}
