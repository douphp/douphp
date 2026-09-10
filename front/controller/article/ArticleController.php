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

namespace Dou\Front\Controller\Article;

use Dou\Core\Facade\RouteId;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Model\Article\Article;
use Dou\Front\Model\Article\ArticleCategory;
use Dou\Front\Service\Article\ArticleService;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SchemaService;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台文章控制器
 */
class ArticleController extends BaseController
{
    /** @var ArticleService */
    private $articleService;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /** @var SeoResolver */
    private $seo;

    /** @var SchemaService */
    private $schema;

    /**
     * @param ArticleService $articleService
     * @param NavigationBuilder $nav
     * @param BreadcrumbBuilder $breadcrumb
     * @param SeoResolver $seo
     * @param SchemaService $schema
     */
    public function __construct(
        ArticleService $articleService,
        NavigationBuilder $nav,
        BreadcrumbBuilder $breadcrumb,
        SeoResolver $seo,
        SchemaService $schema
    ) {
        $this->articleService = $articleService;
        $this->nav = $nav;
        $this->breadcrumb = $breadcrumb;
        $this->seo = $seo;
        $this->schema = $schema;
    }

    /**
     * 文章列表 / 分类 / 归档
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $catId = RouteId::category(
            'article_category',
            $request->route('id'),
            $request->route('category_slug'),
            $request->route('year'),
            $request->route('month')
        );
        if ($catId == -1) {
            throw new DomainException('page_wrong', HOME_URL);
        }

        // 日期归档
        $archive = Util::parseArchive($request->route('year'), $request->route('month'));
        if ($archive) {
            $catId = 0;
        }

        $pageSize = (int) Config::get('pagination.article', 10);
        $pageSize = $pageSize ? $pageSize : 10;
        $result = $this->articleService->buildArticleListData(
            $catId,
            $request->route('page', 1),
            $pageSize,
            $archive
        );

        $cateInfo = $this->buildArticleCategoryInfo($catId);
        if (!is_array($cateInfo)) {
            $cateInfo = array(
                'name' => '',
                'keywords' => '',
                'description' => '',
                'parent_id' => 0,
            );
        }
        if ($archive) {
            $cateInfo['name'] = Util::archiveLabel($archive);
        }

        $urHere = $this->breadcrumb->build('article_category', $catId, '', $archive);

        return $this->view('article_category.dwt', array(
            // 页面头信息
            'page_title' => $this->seo->pageTitle('article_category', $catId, '', $archive),
            'keywords' => $this->seo->keywords($cateInfo['keywords'], $archive),
            'description' => $this->seo->description($cateInfo['description'], $archive, 'article'),

            // 导航栏
            'nav_middle_list' => $this->nav->middle(0, 'article_category', $catId, $cateInfo['parent_id']),

            // 页面骨架
            'rec' => 'index',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'keyword_article' => $this->buildKeywordArticle($request),
            'cate_info' => $cateInfo,
            'article_category' => ArticleCategory::tree($catId),
            'article_list' => $result['article_list'],
            'related_article' => Article::related($catId, 4),
            'column_name' => $cateInfo['name'] ? $cateInfo['name'] : lang('article_category'),
            'pager' => $result['pager'],
        ));
    }

    /**
     * 文章详情
     *
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $id = RouteId::column(
            'article',
            $request->route('id'),
            $request->route('category_slug'),
            $request->route('slug'),
            $request->route('year'),
            $request->route('month')
        );
        if ($id == -1) {
            throw new DomainException('page_wrong', HOME_URL);
        }

        $article = $this->articleService->buildArticleShowData($id);
        if ($article === null) {
            throw new DomainException('page_wrong', HOME_URL);
        }

        // 访问统计
        $this->articleService->recordArticleView($id);
        $article['click'] = (int) $article['click'] + 1;

        $catId = $article['category_id'];
        $cateInfo = $this->buildArticleCategoryInfo($catId);
        if (!is_array($cateInfo)) {
            $cateInfo = array(
                'name' => '',
                'keywords' => '',
                'description' => '',
                'parent_id' => 0,
            );
        }
        $parentId = $cateInfo['parent_id'];

        $comment = Module::make('comment');
        $commentData = null;
        if ($comment) {
            $page = $request->route('page', 1);
            $commentData = $comment->data('article', $id, 10, $page);
        }

        $schema = $this->schema->article($article, $cateInfo);
        $codeHead = $schema ? $schema . Config::get('site.code_head', '') : Config::get('site.code_head', '');

        $archive = Util::parseArchive($request->route('year'), $request->route('month'));
        $urHere = $this->breadcrumb->build('article_category', $catId, $article['title'], $archive);

        $viewData = array(
            // 页面头信息
            'page_title' => $this->seo->pageTitle('article_category', $catId, $article['title']),
            'keywords' => $this->seo->keywords($article['keywords']),
            'description' => $this->seo->description($article['description']),

            // 导航栏
            'nav_middle_list' => $this->nav->middle(0, 'article_category', $catId, $parentId),

            // 页面骨架
            'rec' => 'detail',
            'code_head' => $codeHead,
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'keyword_article' => $this->buildKeywordArticle($request),
            'article_category' => ArticleCategory::tree($catId),
            'lift' => Article::lift($id, $catId),
            'article' => $article,
            'related_article' => Article::related($catId, 4),
            'defined' => $article['defined'],
            'column_name' => $cateInfo['name'] ? $cateInfo['name'] : lang('article_category'),
        );
        if ($commentData !== null) {
            $viewData['comment'] = $commentData;
        }

        return $this->view('article.dwt', $viewData);
    }

    /**
     * @param int $catId
     * @return array|false
     */
    private function buildArticleCategoryInfo($catId)
    {
        $cateInfo = $this->articleService->findCategoryById($catId);
        if (is_array($cateInfo)) {
            $cateInfo = language()->langBox($cateInfo, 'article_category', 'name, keywords, description');
            $cateInfo['category_id'] = (int) $catId;
            $cateInfo['url'] = route('article.category', array('category_id' => $catId));
        }

        return $cateInfo;
    }

    /**
     * 文章侧栏搜索框当前关键词（与全站搜索参数 q 一致，非法词不回填）
     *
     * @param Request $request
     * @return string
     */
    private function buildKeywordArticle(Request $request)
    {
        $qInput = $request->input('q');
        $keywordArticle = '';
        if ($qInput !== null && $qInput !== '') {
            $qStr = is_string($qInput) ? trim($qInput) : '';
            if ($qStr !== '' && Check::searchKeyword($qStr)) {
                $keywordArticle = $qStr;
            }
        }

        return $keywordArticle;
    }

    protected function layoutVars()
    {
        return array(
            'nav_top_list' => $this->nav->top(),
            'nav_bottom_list' => $this->nav->bottom(),
        );
    }
}
