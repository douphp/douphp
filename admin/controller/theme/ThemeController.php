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

namespace Dou\Admin\Controller\Theme;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Theme\ThemeSlugFormRequest;
use Dou\Admin\Service\Cloud\CloudService;
use Dou\Admin\Service\Data\TemplateDataScanner;
use Dou\Admin\Service\Theme\ThemeService;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Theme\SiteThemePolicy;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台模板（route=theme/...）
 */
class ThemeController extends BaseController
{

    /** @var ThemeService */
    private $themeService;

    /** @var CloudService */
    private $cloudService;

    /** @var SiteThemePolicy */
    private $themePolicy;

    /** @var TemplateDataScanner */
    private $dataScanner;

    /**
     * @param ThemeService         $themeService
     * @param CloudService         $cloudService
     * @param SiteThemePolicy      $themePolicy
     * @param TemplateDataScanner  $dataScanner
     */
    public function __construct(ThemeService $themeService, CloudService $cloudService, SiteThemePolicy $themePolicy, TemplateDataScanner $dataScanner)
    {
        $this->themeService = $themeService;
        $this->cloudService = $cloudService;
        $this->themePolicy = $themePolicy;
        $this->dataScanner = $dataScanner;
    }

    /**
     * @return Response
     */
    public function index()
    {
        $data = $this->themeService->buildThemeListData(
            Config::get('site.site_theme', ''),
            $this->themePolicy->themeBlocked()
        );

        return $this->view('theme.htm', [
            'ur_here' => lang('theme'),
            'page_actions' => array(
                array('href' => route('admin.theme.set'), 'text' => lang('set'), 'style' => ''),
            ),
            'rec' => 'default',
            'theme_enable' => $data['theme_enable'],
            'theme_list' => $data['theme_list'],
            'theme_no_allow' => $data['theme_no_allow'],
            'support_module' => $data['support_module'],
        ]);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function install(Request $request)
    {
        $assigns = $this->themeService->buildThemeInstallData($request->get());
        $cloudExtend = $this->cloudService->fetchExtendList('theme', $request->get(), $assigns['localsite']);

        return $this->view('theme.htm', [
            'ur_here' => lang('theme'),
            'page_actions' => array(
                array('href' => route('admin.theme.set'), 'text' => lang('set'), 'style' => ''),
            ),
            'rec' => 'install',
            'cloud_extend' => $cloudExtend,
            // 安装状态仅通过卡片边框色区分，保持图片网格布局与文字链接按钮不变
            'cloud_extend_show_state' => true,
            'cloud_extend_error' => $cloudExtend === null ? lang('cloud_connect_failed') : '',
        ]);
    }

    /**
     * @param ThemeSlugFormRequest $formRequest
     * @return Response
     */
    public function enable(ThemeSlugFormRequest $formRequest)
    {
        $data = $formRequest->validated();
        $this->themeService->enableTheme($data['slug']);

        return redirect(route('admin.theme'));
    }

    /**
     * @param ThemeSlugFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function destroy(ThemeSlugFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $slug = $data['slug'];
        $this->themeService->deleteTheme($slug);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, $slug);

        return redirect(route('admin.theme'));
    }

    /**
     * @return Response
     */
    public function module()
    {
        $this->themeService->ensureThemeParameterRows();
        $this->themeService->syncSupportModuleFromApi();

        return redirect(route('admin.theme'));
    }

    /**
     * @return Response
     */
    public function moduleClear()
    {
        $this->themeService->clearSupportModule();

        return redirect(route('admin.theme'));
    }

    /**
     * 按当前启用主题的模板声明补齐缺失的数据片段。
     *
     * 路由：POST ?route=theme/sync_data（模板管理页「当前启用模板」内的同步按钮）。
     *
     * @return Response
     */
    public function syncData()
    {
        $result = $this->dataScanner->syncCurrentTheme();

        $message = strtr(lang('data_init_result'), array(
            '{scanned}'  => $result['scanned'],
            '{inserted}' => $result['inserted'],
            '{skipped}'  => $result['skipped'],
        ));

        return redirect(route('admin.theme'))->with('success', $message);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function set(Request $request)
    {
        $url = $this->themeService->resolveSetRedirectUrl($request->input('act'));

        return redirect($url);
    }
}
