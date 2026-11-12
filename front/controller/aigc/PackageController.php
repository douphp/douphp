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
 * Release Date: 2026-11-11
 */

namespace Dou\Front\Controller\Aigc;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Service\Aigc\AigcService;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * aigc 套餐页。
 */
class PackageController extends BaseController
{
    /** @var AigcService */
    private $aigcService;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /** @var SeoResolver */
    private $seo;

    public function __construct(AigcService $aigcService, NavigationBuilder $nav, BreadcrumbBuilder $breadcrumb, SeoResolver $seo)
    {
        $this->aigcService = $aigcService;
        $this->nav = $nav;
        $this->breadcrumb = $breadcrumb;
        $this->seo = $seo;
    }

    /**
     * @return Response
     */
    public function index()
    {
        $userId = (int) auth('front')->id();
        $urHere = $this->breadcrumb->build('aigc', 'aigc_package');

        return $this->view('aigc_package.dwt', array(
            'page_title' => $this->seo->pageTitle('aigc', 'aigc_package'),
            'rec' => 'package',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),
            'package_list' => $this->aigcService->packages(),
            'quota_status' => $this->aigcService->quotaStatus($userId),
        ));
    }

    /**
     * @return array
     */
    protected function layoutVars()
    {
        return array(
            'keywords' => $this->seo->keywords(),
            'description' => $this->seo->description(),
            'nav_top_list' => $this->nav->top(),
            'nav_middle_list' => $this->nav->middle(),
            'nav_bottom_list' => $this->nav->bottom(),
            'link_user_center' => $this->buildLinkUserCenter('aigc'),
            'if_connect_plugin' => plugin()->hasConnect(),
        );
    }
}
