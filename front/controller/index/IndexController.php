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

namespace Dou\Front\Controller\Index;

use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Service\Index\IndexService;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台首页控制器
 */
class IndexController extends BaseController
{
    /** @var IndexService */
    private $indexService;

    /** @var NavigationBuilder */
    private $nav;

    /** @var SeoResolver */
    private $seo;

    /**
     * @param IndexService $indexService
     * @param NavigationBuilder $nav
     * @param SeoResolver $seo
     */
    public function __construct(IndexService $indexService, NavigationBuilder $nav, SeoResolver $seo)
    {
        $this->indexService = $indexService;
        $this->nav = $nav;
        $this->seo = $seo;
    }

    /**
     * 首页
     *
     * @return Response
     */
    public function index()
    {
        $data = $this->indexService->buildIndexData((int) auth('front')->id());

        if (file_exists($codeIncludeFile = ROOT_PATH . '..code.php')) {
            require $codeIncludeFile;
        }

        return $this->view('index.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle(),

            // 页面骨架
            'rec' => 'index',
            'code_head' => $data['code_head'],

            // 数据
            'show_list' => $data['show_list'],
            'index' => $data['index'],
            'recommend_product' => $data['recommend_product'],
            'new_product' => $data['new_product'],
            'recommend_article' => $data['recommend_article'],
            'product_category' => $data['product_category'],
        ]);
    }

    protected function layoutVars()
    {
        return array(
            'keywords' => $this->seo->keywords(),
            'description' => $this->seo->description(),
            'nav_top_list' => $this->nav->top(),
            'nav_middle_list' => $this->nav->middle(),
            'nav_bottom_list' => $this->nav->bottom(),
        );
    }
}
