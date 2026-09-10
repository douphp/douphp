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

namespace Dou\Front\Service\Sitemap;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Module\ContentTypeRegistry;
use Dou\Core\Service\BaseService;
use Dou\Front\Model\Page\Page;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台 Sitemap XML 业务层
 */
class SitemapService extends BaseService
{
    /** @var string */
    protected $header = "<\x3Fxml version=\"1.0\" encoding=\"UTF-8\"\x3F>\n\t<urlset xmlns=\"http://www.google.com/schemas/sitemap/0.84\">";

    /** @var string */
    protected $footer = "\t</urlset>";

    /** @var string */
    protected $output;

    /** @var string */
    protected $domain = '';

    /** @var string */
    protected $today = '';

    /** @var ContentTypeRegistry */
    private $types;

    /**
     * @param ContentTypeRegistry $types 内容类型注册表（按 sitemap 通道筛出导出 Model）
     */
    public function __construct(ContentTypeRegistry $types)
    {
        $this->types = $types;
        $this->domain = defined('ROOT_URL') ? ROOT_URL : '';
        $this->today = date('Y-m-d');
    }

    /**
     * 生成完整 sitemap XML 字符串
     *
     * @return string
     */
    public function buildSitemap()
    {
        $output = $this->header . "\n\n";
        $output .= $this->readItem();

        $langMenu = language()->getLangMenu();
        if ($langMenu) {
            foreach ($langMenu as $lang) {
                $GLOBALS['_CUR_LANG_DEFINE'] = array(
                    'mode' => Config::get('site.rewrite', false) ? 'rewrite_open' : 'rewrite_close',
                    'sign' => Config::get('site.rewrite', false) ? str_replace('_', '-', $lang['language_pack']) : $lang['language_pack'],
                    'pack' => $lang['language_pack'],
                );

                $output .= $this->readItem($lang['url']);
            }
            unset($GLOBALS['_CUR_LANG_DEFINE']);
        }
        $output .= $this->footer;
        return $output;
    }

    /**
     * @param string $home_url
     * @return string
     */
    protected function readItem($home_url = '')
    {
        $home_url = $home_url ? $home_url : $this->domain;
        $item = $this->arrayItem();

        $arr = "\t\t<url>\n";
        $arr .= "\t\t\t<loc>$home_url</loc>\n";
        $arr .= "\t\t\t<lastmod>$this->today</lastmod>\n";
        $arr .= "\t\t\t<changefreq>hourly</changefreq>\n";
        $arr .= "\t\t\t<priority>0.9</priority>\n";
        $arr .= "\t\t</url>\n\n";

        foreach ($item as $row) {
            $arr .= "\t\t<url>\n";
            $arr .= "\t\t\t<loc>" . htmlspecialchars($row['url'], ENT_XML1, 'UTF-8') . "</loc>\n";
            $arr .= "\t\t\t<lastmod>$row[date]</lastmod>\n";
            $arr .= "\t\t\t<changefreq>$row[changefreq]</changefreq>\n";
            $arr .= "\t\t\t<priority>0.9</priority>\n";
            $arr .= "\t\t</url>\n\n";
        }

        return $arr;
    }

    /**
     * @return array
     */
    protected function arrayItem()
    {
        $item_array = array();

        // 单页面列表
        foreach (Page::pageNolevel() as $row) {
            $item_array[] = array(
                "date" => $this->today,
                "changefreq" => 'weekly',
                "url" => $row['url']
            );
        }

        // 内容模块（column 走分类树 + 内容；single 走模块入口 + 内容）
        foreach ($this->types->forSitemap() as $cls) {
            $schema = $cls::moduleSchema();
            $module = $schema['module'];
            $kind = isset($schema['kind']) ? $schema['kind'] : 'column';

            if ($kind === 'column') {
                $item_array[] = array(
                    "date" => $this->today,
                    "changefreq" => 'hourly',
                    "url" => route($module . '.category')
                );
                foreach ($cls::categoriesForExport() as $row) {
                    $item_array[] = array(
                        "date" => $this->today,
                        "changefreq" => 'hourly',
                        "url" => $row['url']
                    );
                }
            } else {
                $item_array[] = array(
                    "date" => $this->today,
                    "changefreq" => 'weekly',
                    "url" => route($module)
                );
            }

            // 内容列表
            foreach ($cls::listForExport() as $row) {
                $item_array[] = array(
                    "date" => $row['created_at'],
                    "changefreq" => 'weekly',
                    "url" => $row['url']
                );
            }
        }

        return $item_array;
    }
}
