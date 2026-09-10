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

namespace Dou\Admin\Service\Ai\Context;

use Dou\Admin\Model\Page\Page;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 组装 AI 创作用的站点基础信息：设置 + 单页面正文摘要。
 */
class SiteKnowledgeBuilder extends BaseService
{
    const PAGE_CONTENT_LIMIT = 1200;
    const TOTAL_LIMIT = 8000;

    /**
     * @return string 空串表示没有可注入的站点信息
     */
    public function build()
    {
        $parts = array();

        $siteName = trim((string) Config::get('site.site_name', ''));
        $siteTitle = trim((string) Config::get('site.site_title', ''));
        $siteKeywords = trim((string) Config::get('site.site_keywords', ''));
        $siteDescription = trim((string) Config::get('site.site_description', ''));

        if ($siteName !== '') {
            $parts[] = '站点名称：' . $siteName;
        }
        if ($siteTitle !== '') {
            $parts[] = '站点标题：' . $siteTitle;
        }
        if ($siteKeywords !== '') {
            $parts[] = '站点关键字：' . $siteKeywords;
        }
        if ($siteDescription !== '') {
            $parts[] = '站点简介：' . $siteDescription;
        }

        $pageBlock = $this->buildPageBlock();
        if ($pageBlock !== '') {
            $parts[] = $pageBlock;
        }

        $text = implode("\n", $parts);
        if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > self::TOTAL_LIMIT) {
            $text = Str::limit($text, self::TOTAL_LIMIT, '...');
        } elseif (strlen($text) > self::TOTAL_LIMIT * 3) {
            $text = Str::limit($text, self::TOTAL_LIMIT, '...');
        }

        return $text;
    }

    /**
     * @return string
     */
    private function buildPageBlock()
    {
        $rows = Page::orderBy('id', 'ASC')->get();
        if (!$rows) {
            return '';
        }

        $chunks = array();
        $used = 0;
        foreach ($rows as $row) {
            $name = trim((string) $row['name']);
            $content = $this->plainText(isset($row['content']) ? $row['content'] : '');
            if ($name === '' && $content === '') {
                continue;
            }

            $excerpt = $content === '' ? '' : Str::limit($content, self::PAGE_CONTENT_LIMIT, '...');
            $chunk = $name !== '' ? '【' . $name . '】' : '【单页】';
            if ($excerpt !== '') {
                $chunk .= "\n" . $excerpt;
            }

            $chunkLen = function_exists('mb_strlen') ? mb_strlen($chunk, 'UTF-8') : strlen($chunk);
            if ($used > 0 && ($used + $chunkLen) > self::TOTAL_LIMIT) {
                break;
            }
            $chunks[] = $chunk;
            $used += $chunkLen;
        }

        if (!$chunks) {
            return '';
        }

        return "单页面：\n" . implode("\n\n", $chunks);
    }

    /**
     * @param mixed $html
     * @return string
     */
    private function plainText($html)
    {
        $text = strip_tags((string) $html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return trim((string) $text);
    }
}
