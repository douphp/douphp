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

namespace Dou\Front\Controller\Page;

use Dou\Core\Facade\RouteId;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Model\Page\Page;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Page\PageService;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SchemaService;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 单页面控制器
 */
class PageController extends BaseController
{
    /** @var PageService */
    private $pageService;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /** @var SeoResolver */
    private $seo;

    /** @var SchemaService */
    private $schema;

    /**
     * @param PageService $pageService
     * @param NavigationBuilder $nav
     * @param BreadcrumbBuilder $breadcrumb
     * @param SeoResolver $seo
     * @param SchemaService $schema
     */
    public function __construct(
        PageService $pageService,
        NavigationBuilder $nav,
        BreadcrumbBuilder $breadcrumb,
        SeoResolver $seo,
        SchemaService $schema
    ) {
        $this->pageService = $pageService;
        $this->nav = $nav;
        $this->breadcrumb = $breadcrumb;
        $this->seo = $seo;
        $this->schema = $schema;
    }

    /**
     * 单页详情：由 PrettyRouteMatcher 对 page 类型规则派发 action=show
     *
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $id = RouteId::page($request->route('id'), $request->route('slug'));
        if ($id == -1) {
            throw new DomainException('page_wrong', HOME_URL);
        }

        $data = $this->pageService->buildPageShowData($id, $request->input('editor_code'));
        if ($data === null) {
            throw new DomainException('page_wrong', HOME_URL);
        }

        $page = $data['page'];
        $top = $data['top'];
        $topId = $data['top_id'];

        $schema = $this->schema->page($page, $top);
        $codeHead = $schema ? $schema . Config::get('site.code_head', '') : Config::get('site.code_head', '');
        $smarty_page_title = $this->seo->pageTitle('page', '', $page['name']);

        $urHere = $this->breadcrumb->build('page', '', $page['name']);
        $smarty_breadcrumb = $this->breadcrumb->schema($urHere);
        $smarty_page_list = Page::pageTree($topId, $id);

        $dataList = data()->query('page', $page['slug']);

        $viewData = array(
            // 页面头信息
            'page_title' => $smarty_page_title,
            'keywords' => $this->seo->keywords($page['keywords']),
            'description' => $this->seo->description($page['description']),

            // 导航栏
            'nav_middle_list' => $this->nav->middle(0, 'page', $id),

            // 页面骨架
            'rec' => 'index',
            'code_head' => $codeHead,
            'ur_here' => $urHere,
            'breadcrumb' => $smarty_breadcrumb,

            // 数据
            'editor_mode' => !empty($data['editor_mode']),
            'page_list' => $smarty_page_list,
            'top' => $top,
            'page' => $page,
            'top_cur' => ($topId == $id) ? 'top_cur' : '',
        );
        if ($dataList !== null) {
            $viewData['data_list'] = $dataList;
        }

        $themeDir = ROOT_PATH . 'theme/' . Config::get('site.site_theme', '') . '/';
        if (file_exists($themeDir . $page['slug'] . '.dwt')) {
            return $this->view($page['slug'] . '.dwt', $viewData);
        }

        return $this->view('page.dwt', $viewData);
    }

    protected function layoutVars()
    {
        return array(
            'nav_top_list' => $this->nav->top(),
            'nav_bottom_list' => $this->nav->bottom(),
        );
    }
}
