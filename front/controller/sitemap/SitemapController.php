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

namespace Dou\Front\Controller\Sitemap;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Front\Controller\BaseController;
use Dou\Front\Service\Sitemap\SitemapService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * Sitemap 控制器
 */
class SitemapController extends BaseController
{
    /** @var SitemapService */
    private $sitemapService;

    /**
     * @param SitemapService $sitemapService
     */
    public function __construct(SitemapService $sitemapService)
    {
        $this->sitemapService = $sitemapService;
    }

    public function index()
    {
        if (!intval(Config::get('site.sitemap', ''))) {
            return $this->response('');
        }

        $xml = $this->sitemapService->buildSitemap();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        return $this->response($xml, 200, array('Content-Type' => 'application/xml; charset=utf-8'));
    }
}
