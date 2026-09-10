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

namespace Dou\Api\Controller\Article;

use Dou\Api\Controller\BaseController;
use Dou\Core\Facade\RouteId;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Model\Article\ArticleCategory;
use Dou\Front\Service\Article\ArticleService;

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

    /**
     * @param ArticleService $articleService
     */
    public function __construct(ArticleService $articleService)
    {
        $this->articleService = $articleService;
    }

    /**
     * 文章列表 / 分类 / 归档
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $catId = RouteId::category('article_category', $request->input('id'), $request->input('category_slug'));
        if ($catId == -1) {
            throw new DomainException(lang('page_wrong'));
        }

        // 日期归档
        $archive = Util::parseArchive($request->input('year'), $request->input('month'));
        if ($archive) {
            $catId = 0;
        }

        $pageSize = Config::get('pagination.article', 10);
        $data = $this->articleService->buildArticleListData(
            $catId,
            $request->integer('page', 1),
            $pageSize,
            $archive
        );

        $articleList = $data['article_list'];
        $cateInfo = $this->buildArticleCategoryInfo($catId);

        $title = (is_array($cateInfo) && !empty($cateInfo['name'])) ? $cateInfo['name'] : lang('article');

        $data = array(
            'title' => $title,
            'category_id' => $catId,
            'article_list' => $articleList,
            'article_category' => ArticleCategory::tree($catId),
            'cate_info' => $cateInfo,
        );

        return ApiResponse::success($data);
    }

    /**
     * 文章详情
     *
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $id = RouteId::column('article', $request->input('id'), $request->input('category_slug'), $request->input('slug'));
        if ($id == -1) {
            throw new DomainException(lang('page_wrong'));
        }

        $article = $this->articleService->buildArticleShowData($id);
        if ($article === null) {
            throw new DomainException(lang('page_wrong'));
        }

        // 访问统计
        $this->articleService->recordArticleView($id);
        $article['click'] = (int) $article['click'] + 1;

        // 数据封装
        $data['defined'] = $article['defined'];
        $data['title'] = lang('article_detail');
        $comment = Module::make('comment');
        $data['comment'] = $comment ? $comment->data('article', $id, 10, $request->integer('page', 1)) : array();
        $data['article'] = $article;

        return ApiResponse::success($data);
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
            $cateInfo['url'] = route('article.category', array('category_id' => $catId));
        }

        return $cateInfo;
    }
}
