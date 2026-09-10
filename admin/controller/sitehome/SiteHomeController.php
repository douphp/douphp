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
use Dou\Admin\Service\Data\TemplateDataScanner;
use Dou\Admin\Service\SiteHome\SiteHomeService;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

class SiteHomeController extends BaseController
{
    /** @var SiteHomeService */
    private $siteHomeService;

    /** @var TemplateDataScanner */
    private $dataScanner;

    /**
     * @param SiteHomeService $siteHomeService
     * @param TemplateDataScanner $dataScanner
     */
    public function __construct(SiteHomeService $siteHomeService, TemplateDataScanner $dataScanner)
    {
        $this->siteHomeService = $siteHomeService;
        $this->dataScanner = $dataScanner;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'site_home',
        );
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
                array(
                    'href' => route('admin.site_home.sync'),
                    'text' => lang('data_sync_from_template'),
                    'style' => 'gray',
                    'post' => true,
                ),
            ),
            'page_cue' => '<p>' . lang('show_cue') . ' <i class="bi-chevron-right"></i></p>',
            'site_logo' => $this->siteHomeService->buildSiteLogoUrl(SYSTEM_SIGN),
            'show_list' => Show::showList($type),
            'rec' => 'default',
            'data_list' => $dataList,
        ]);
    }

    /**
     * 一键扫描当前主题模板，把缺失的 data 行自动补建到 dou_data 表。
     *
     * 路由：POST ?route=site_home/sync（由 site_home 页面「同步模板数据」按钮触发）。
     * 已存在的 code 完全跳过；新生成的条目 is_locked=1；flash 提示新增/跳过条数后回到 site_home。
     *
     * @return Response
     */
    public function sync()
    {
        $result = $this->dataScanner->syncCurrentTheme();

        $message = strtr(lang('data_sync_result'), array(
            '{scanned}' => $result['scanned'],
            '{inserted}' => $result['inserted'],
            '{skipped}' => $result['skipped'],
        ));

        return redirect(route('admin.site_home'))->with('success', $message);
    }
}
