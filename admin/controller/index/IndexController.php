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
use Dou\Admin\Service\Workspace\UpdateBadgeBuilder;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Web\Http\ApiResponse;
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
     * @return Response
     */
    public function index()
    {
        $this->indexService->syncEmptyConfigDomainToRootUrl();

        $root_url = Config::get('site.root_url', '') !== '' ? rtrim(Config::get('site.root_url', ''), '/') : '';
        $cue_set_domain = $this->indexService->shouldCueSetDomainFromConfig()
            || ($root_url !== '' && $root_url !== rtrim(ROOT_URL, '/'));

        return $this->view('index.htm', [
            'cue_set_domain' => $cue_set_domain,
            'rec' => 'default',
            'sys_info' => $this->indexService->buildSysInfo(),
            'page_list' => Page::pageNolevel(),
            'log_list' => $this->indexService->getRecentAdminLogs(),
            'backup' => $this->indexService->buildBackupAssign(),
            'quick_start' => $this->indexService->buildQuickStartItems(),
        ]);
    }

    /**
     * 更新角标异步刷新端点（JSON API，GET）。
     *
     * 供首页 / 安装页在 DOM ready 或 finalize 后由 JS 静默调用，避免同步等待云端
     * `/connect`（默认超时 30s）阻塞页面渲染。`force=1` 跳过 10 分钟节流强制刷新，
     * 用于模块（含批量）升级完成后即时同步角标。
     *
     * 关闭升级（`site.close_update`）时直接返回 closed，不发云端请求。
     *
     * @param Request $request
     * @return \Dou\Core\Web\Http\JsonResponse
     */
    public function updateNumber(Request $request)
    {
        if (Config::get('site.close_update', false)) {
            return ApiResponse::success(array('closed' => true, 'unum' => null));
        }

        $forceRaw = (string) $request->query('force', '');
        $force = $forceRaw !== '' && $forceRaw !== '0';

        $fresh = $this->cloudService->refreshUpdateNumber(
            $this->cloud->localSitePayload(),
            $this->cloud->localSystemPayload(),
            $force
        );

        $badge = app(UpdateBadgeBuilder::class);
        $unum = is_array($fresh) ? $badge->fromRaw($fresh) : $badge->build();

        return ApiResponse::success(array('closed' => false, 'unum' => $unum));
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
