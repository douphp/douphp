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

namespace Dou\Front\Controller\Search;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Model\Article\ArticleCategory;
use Dou\Front\Model\Product\ProductCategory;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Search\SearchService;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 全站搜索（关键词统一用查询参数 q，如 index.php?route=search&q= 或伪静态 /search?q=）
 */
class SearchController extends BaseController
{
    /** @var SearchService */
    private $searchService;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /** @var SeoResolver */
    private $seo;

    /**
     * @param SearchService $searchService
     * @param NavigationBuilder $nav
     * @param BreadcrumbBuilder $breadcrumb
     * @param SeoResolver $seo
     */
    public function __construct(
        SearchService $searchService,
        NavigationBuilder $nav,
        BreadcrumbBuilder $breadcrumb,
        SeoResolver $seo
    ) {
        $this->searchService = $searchService;
        $this->nav = $nav;
        $this->breadcrumb = $breadcrumb;
        $this->seo = $seo;
    }

    /**
     * 搜索页：参数 q、module、category_id、page、by、sort
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $qParam = $request->input('q');
        $raw = $qParam !== null ? $qParam : '';
        $keyword = is_string($raw) ? trim($raw) : '';

        if ($keyword !== '' && !Check::searchKeyword($keyword)) {
            throw new DomainException(lang('search_keyword_wrong'), HOME_URL);
        }

        $catId = $request->integer('category_id', 0);

        $searchModule = '';
        $modInput = $request->input('module');
        if ($modInput !== null && $modInput !== '' && Check::letter($modInput)) {
            $searchModule = trim($modInput);
        }

        $page = $request->route('page', 1);

        $sortBy = $request->input('by');
        $sortBy = $sortBy !== null ? $sortBy : '';
        $sortDir = $request->input('sort');
        $sortDir = $sortDir !== null ? $sortDir : '';

        $data = $this->searchService->buildSearchResultData(
            $keyword,
            $searchModule,
            $catId,
            $page,
            $sortBy,
            $sortDir
        );

        $searchResults = isset($data['search_results']) ? $data['search_results'] : '';

        $urHere = $this->breadcrumb->build('page', '', $searchResults);

        $viewData = array(
            // 页面头信息
            'page_title' => $this->seo->pageTitle('search', '', $searchResults),

            // 导航栏
            'nav_middle_list' => $this->nav->middle(),

            // 页面骨架
            'rec' => 'index',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'search_module' => isset($data['search_module']) ? $data['search_module'] : '',
            'product_category' => ProductCategory::tree(),
            'article_category' => ArticleCategory::tree(),
            'search_results' => $searchResults,
            'search_list' => isset($data['search_list']) ? $data['search_list'] : array(),
            'keyword' => isset($data['keyword']) ? $data['keyword'] : '',
            'pager' => isset($data['pager']) && is_array($data['pager']) ? $data['pager'] : array(),
        );
        if (isset($data['sort_list']) && $data['sort_list'] !== null) {
            $viewData['sort_list'] = $data['sort_list'];
        }

        return $this->view('search.dwt', $viewData);
    }

    protected function layoutVars()
    {
        return array(
            'keywords' => $this->seo->keywords(),
            'description' => $this->seo->description(),
            'nav_top_list' => $this->nav->top(),
            'nav_bottom_list' => $this->nav->bottom(),
        );
    }
}
