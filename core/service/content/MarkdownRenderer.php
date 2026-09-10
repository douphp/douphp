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
 * - admin 在 `vditor` 编辑器场景直接返回原文。
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

        if (defined('IS_ADMIN') && Config::get('site.editor', '') == 'vditor') {
            return $content;
        }

        if (Util::containsMarkdownSyntax($content)) {
            $parsedown = new Parsedown();
            $content = $parsedown->text($content);
        }

        return $content;
    }
}
