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

namespace Dou\Admin\Controller\Index;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Facade\Cloud;
use Dou\Admin\Model\Page\Page;
use Dou\Admin\Service\Cloud\CloudService;
use Dou\Admin\Service\Index\IndexService;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台首页（控制台）
 */
class IndexController extends BaseController
{
    /** @var IndexService */
    private $indexService;

    /** @var Cloud */
    private $cloud;

    /** @var CloudService */
    private $cloudService;

    /**
     * @param IndexService $indexService
     * @param Cloud $cloud
     * @param CloudService $cloudService
     */
    public function __construct(
        IndexService $indexService,
        Cloud $cloud,
        CloudService $cloudService
    ) {
        $this->indexService = $indexService;
        $this->cloud = $cloud;
        $this->cloudService = $cloudService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'index',
        );
    }

    /**
     * @return Response
     */
    public function index()
    {
        $domain = $this->indexService->runDomainCacheMaintenance();
        if (!empty($domain['redirect_url'])) {
            return redirect($domain['redirect_url']);
        }

        $this->indexService->syncEmptyConfigDomainToRootUrl();

        $root_url = Config::get('site.root_url', '') !== '' ? rtrim(Config::get('site.root_url', ''), '/') : '';
        $cue_set_domain = $this->indexService->shouldCueSetDomainFromConfig()
            || ($root_url !== '' && $root_url !== rtrim(ROOT_URL, '/'));

        $cache_root_url_cue = '';
        if (!empty($domain['had_domain_cache_file']) && lang_has('cache_root_url_cue')) {
            $cache_root_url_cue = lang('cache_root_url_cue');
        }

        if (!Config::get('site.close_update', false)) {
            $this->cloudService->refreshUpdateNumber(
                $this->cloud->localSitePayload(),
                $this->cloud->localSystemPayload()
            );
        }

        return $this->view('index.htm', [
            'cue_set_domain' => $cue_set_domain,
            'cache_root_url_cue' => $cache_root_url_cue,
            'rec' => 'default',
            'sys_info' => $this->indexService->buildSysInfo(),
            'page_list' => Page::pageNolevel(),
            'log_list' => $this->indexService->getRecentAdminLogs(),
            'backup' => $this->indexService->buildBackupAssign(),
            'quick_start' => $this->indexService->buildQuickStartItems(),
        ]);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function clearCache(Request $request)
    {
        $this->indexService->clearAllCache();
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CACHE_CLEAR, 1);
        return message()->respond(lang('clear_cache_success'), route('admin.index'));
    }

    /**
     * @return Response
     */
    public function closeQuickStart()
    {
        $this->indexService->clearQuickStartFlag();
        return redirect(route('admin.index'));
    }

    /**
     * @return Response
     */
    public function deleteInstall()
    {
        $this->indexService->deleteInstallDirectory();
        return redirect(route('admin.index'));
    }
}
