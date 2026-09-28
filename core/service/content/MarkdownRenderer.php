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
use Dou\Core\Support\Util;
use Dou\Vendor\Parsedown\Parsedown;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * Markdown 渲染读模型：纯文本 → HTML，无任何视图副作用。
 *
 * 行为约定：
 * - 不向输出拼接 `<link href="...content.css">`；前台需要的 CSS 由模板/Init 一次性引入。
 * - editor(ueditor) 站点正文即 HTML，任何场景都原样返回，不做 Markdown 解析。
 * - vditor 站点：admin 编辑表单返回 Markdown 源码，前台才渲染为 HTML。
 */
class MarkdownRenderer extends BaseService
{
    /**
     * 将 Markdown 文本渲染为 HTML；空字符串/无 Markdown 语法时原样返回。
     *
     * @param string|null $content 原始内容
     * @return string
     */
    public function toHtml($content = '')
    {
        $content = $content ? $content : '';

        $isMarkdownEditor = Config::get('site.editor', '') == 'vditor';

        // editor(ueditor) 站点存的就是 HTML：无论前后台都原样返回。
        // 否则 HTML 里多个 `_`（如带下划线的图片文件名）等会被误判为 Markdown 语法，
        // 经 Parsedown safe mode 转义后，编辑器/前台会把标签当纯文本代码显示。
        if (!$isMarkdownEditor) {
            return $content;
        }

        // vditor 站点后台编辑表单：回填 Markdown 源码，原样返回。
        if (defined('IS_ADMIN')) {
            return $content;
        }

        // vditor 站点前台：将 Markdown 渲染为 HTML。
        if (Util::containsMarkdownSyntax($content)) {
            $parsedown = new Parsedown();
            // safe mode：转义 Markdown 中的内联 HTML 并过滤 javascript: 等危险链接协议。
            // 代价是正文里手写的内联 HTML 会按纯文本呈现。
            $parsedown->setSafeMode(true);
            $content = $parsedown->text($content);
        }

        return $content;
    }
}
