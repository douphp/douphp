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

namespace Dou\Admin\Controller\Miniprogram;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Facade\Cloud;
use Dou\Admin\Service\Cloud\CloudService;
use Dou\Admin\Service\Miniprogram\MiniprogramService;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序列表与安装（子路径 nav/show/system 见 Miniprogram 子命名空间控制器）
 */
class MiniprogramController extends BaseController
{
    /** @var MiniprogramService */
    private $miniprogramService;

    /** @var Cloud */
    private $cloud;

    /** @var CloudService */
    private $cloudService;

    /**
     * @param MiniprogramService $miniprogramService
     * @param Cloud              $cloud
     * @param CloudService       $cloudService
     */
    public function __construct(MiniprogramService $miniprogramService, Cloud $cloud, CloudService $cloudService)
    {
        $this->miniprogramService = $miniprogramService;
        $this->cloud = $cloud;
        $this->cloudService = $cloudService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'miniprogram',
        );
    }

    /**
     * @return Response
     */
    public function index()
    {
        $slugs = $this->miniprogramService->listMiniprogramCodeSlugs();
        if (!$slugs) {
            return redirect(route('admin.miniprogram.install'));
        }

        $lists = $this->miniprogramService->buildMiniprogramListData();

        return $this->view('miniprogram.htm', [
            'ur_here' => lang('miniprogram_list'),
            'rec' => 'default',
            'miniprogram_enable' => $lists['miniprogram_enable'],
            'miniprogram_list' => $lists['miniprogram_list'],
        ]);
    }

    /**
     * @return Response
     */
    public function sync()
    {
        $this->miniprogramService->defineCodePath();
        $this->miniprogramService->syncMiniprogramConfig();
        return redirect(route('admin.miniprogram'))->with('success', lang('dou_msg_success'));
    }

    /**
     * @return Response
     */
    public function release()
    {

        return $this->view('miniprogram.htm', [
            'ur_here' => lang('miniprogram_release'),
            'rec' => 'release',
            'MINIPROGRAM_DIR' => MINIPROGRAM_DIR,
        ]);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function install(Request $request)
    {
        $localsite = $this->cloud->localSitePayload('miniprogram');
        $cloudExtend = $this->cloudService->fetchExtendList('miniprogram', $request->get(), $localsite);

        return $this->view('miniprogram.htm', [
            'ur_here' => lang('miniprogram_install'),
            'rec' => 'install',
            'cloud_extend' => $cloudExtend,
            'cloud_extend_error' => $cloudExtend === null ? lang('cloud_connect_failed') : '',
            'cloud_list_class' => 'cloud-list--miniprogram',
        ]);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function enable(Request $request)
    {
        if (Check::extendId($slug = $request->input('slug'))) {
            $this->miniprogramService->enablePackage($slug);
        }

        return redirect(route('admin.miniprogram'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        if (Check::extendId($slug = $request->input('slug'))) {
            $this->miniprogramService->deletePackage($slug);
        }

        return redirect(route('admin.miniprogram'));
    }
}
