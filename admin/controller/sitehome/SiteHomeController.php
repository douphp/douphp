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

namespace Dou\Admin\Controller\SiteHome;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Model\Show\Show;
use Dou\Admin\Service\SiteHome\SiteHomeService;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

class SiteHomeController extends BaseController
{
    /** @var SiteHomeService */
    private $siteHomeService;

    /**
     * @param SiteHomeService $siteHomeService
     */
    public function __construct(SiteHomeService $siteHomeService)
    {
        $this->siteHomeService = $siteHomeService;
    }

    /**
     * @return Response
     */
    public function index()
    {
        $type = $this->siteHomeService->getShowListType(SYSTEM_SIGN);

        $dataList = data()->query('index');

        return $this->view('site_home.htm', [
            'ur_here' => lang('site_home'),
            'page_actions' => array(
                array('href' => route('admin.data.create', ['group' => 'index']), 'text' => lang('data_create'), 'style' => ''),
            ),
            'page_cue' => '<p>' . lang('show_cue') . ' <i class="bi-chevron-right"></i></p>',
            'site_logo' => $this->siteHomeService->buildSiteLogoUrl(SYSTEM_SIGN),
            'show_list' => Show::showList($type),
            'rec' => 'default',
            'data_list' => $dataList,
        ]);
    }
}
