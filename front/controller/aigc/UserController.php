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
use Dou\Core\Web\Http\Request;
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
 * aigc 会员中心：订阅 / 作品 / 对话历史。
 */
class UserController extends BaseController
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

        return $this->renderUserPage('index', array(
            'quota_status' => $this->aigcService->quotaStatus($userId),
            'subscription_list' => $this->aigcService->subscriptions($userId),
        ));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function works(Request $request)
    {
        $userId = (int) auth('front')->id();
        $page = $request->integer('page', 1);
        $bundle = $this->aigcService->works($userId, '', $page, route('aigc.user.works'));

        return $this->renderUserPage('works', array(
            'work_list' => $bundle['list'],
            'pager' => $bundle['pager'],
            'file_url' => route('aigc.file'),
        ));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function history(Request $request)
    {
        $userId = (int) auth('front')->id();
        $rows = \Dou\Core\Facade\DB::table('aigc_session')
            ->where('user_id', $userId)
            ->order('updated_at DESC')
            ->limit(50)
            ->select();
        $list = array();
        foreach ((array) $rows as $row) {
            $app = \Dou\Core\Facade\DB::table('aigc')->where('id', (int) $row['app_id'])->find();
            $list[] = array(
                'title' => (string) $row['title'],
                'app_name' => $app ? (string) $app['name'] : '',
                'slug' => $app ? (string) $app['slug'] : '',
                'session_sn' => (string) $row['session_sn'],
                'message_count' => (int) $row['message_count'],
                'updated_at' => date('Y-m-d H:i', strtotime((string) $row['updated_at'])),
            );
        }

        return $this->renderUserPage('history', array('session_list' => $list));
    }

    /**
     * @param string $tab
     * @param array $data
     * @return Response
     */
    private function renderUserPage($tab, array $data)
    {
        $urHere = $this->breadcrumb->build('aigc', 'aigc_user');

        return $this->view('aigc_user.dwt', array_merge(array(
            'page_title' => $this->seo->pageTitle('aigc', 'aigc_user'),
            'rec' => 'user',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),
            'tab' => $tab,
            'user_index_url' => route('aigc.user'),
            'user_works_url' => route('aigc.user.works'),
            'user_history_url' => route('aigc.user.history'),
            'package_url' => route('aigc.package'),
        ), $data));
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
