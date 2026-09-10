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

namespace Dou\Admin\Controller\Module;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Module\ModuleUninstallFormRequest;
use Dou\Admin\Service\Cloud\CloudService;
use Dou\Admin\Service\Module\ModuleService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台模块扩展控制器。
 *
 * 列表与表单数据由 ModuleService::build*Data 提供；卸载 POST 使用 ModuleUninstallFormRequest。
 */
class ModuleController extends BaseController
{
    /** @var ModuleService */
    private $moduleService;

    /** @var CloudService */
    private $cloudService;

    /**
     * @param ModuleService $moduleService
     * @param CloudService $cloudService
     */
    public function __construct(ModuleService $moduleService, CloudService $cloudService)
    {
        $this->moduleService = $moduleService;
        $this->cloudService = $cloudService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'module',
        );
    }

    /**
     * 在线安装（云端列表）。
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $rawSign = trim((string) $request->input('system_sign', ''));
        $systemSign = (Check::rec($rawSign)) ? $rawSign : '';
        $payload = $this->moduleService->buildModuleIndexData($systemSign);
        $localsite = isset($payload['localsite']) ? $payload['localsite'] : '';
        $cloudExtend = $this->cloudService->fetchExtendList('module', $request->get(), $localsite);

        return $this->view('module.htm', [
            'ur_here' => lang('module'),
            'rec' => 'default',
            'cloud_extend' => $cloudExtend,
            'cloud_extend_error' => $cloudExtend === null ? lang('cloud_connect_failed') : '',
        ]);
    }

    /**
     * 本地安装：列出 cache 下 zip。
     *
     * @return Response
     */
    public function installLocal()
    {
        $data = $this->moduleService->buildModuleInstallLocalData();

        return $this->view('module.htm', [
            'ur_here' => lang('module'),
            'rec' => 'install_local',
            'install_list' => isset($data['install_list']) ? $data['install_list'] : array(),
        ]);
    }

    /**
     * 卸载列表。
     *
     * @return Response
     */
    public function uninstall()
    {
        $data = $this->moduleService->buildModuleUninstallData();

        return $this->view('module.htm', [
            'ur_here' => lang('module'),
            'rec' => 'uninstall',
            'uninstall_list' => isset($data['uninstall_list']) ? $data['uninstall_list'] : array(),
        ]);
    }

    /**
     * 卸载单条：GET 二次确认；POST confirm 后执行卸载。
     *
     * @param ModuleUninstallFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function destroy(ModuleUninstallFormRequest $formRequest, Request $request)
    {
        $post = $request->post();
        $hasConfirm = is_array($post) && array_key_exists('confirm', $post);

        if ($hasConfirm) {
            $validated = $formRequest->validated();
            $extendId = isset($validated['extend_id']) ? trim((string) $validated['extend_id']) : '';

            $this->moduleService->performUninstall($extendId);

            return redirect(route('admin.module.uninstall'));
        }

        $extendId = $request->extendId('extend_id');
        $token = trim((string) $request->input('token', ''));

        if (!Check::extendId($extendId) || $token === '') {
            throw new DomainException(lang('illegal'), route('admin.module.uninstall'));
        }

        $result = $this->moduleService->buildUninstallConfirm($extendId, $token);
        return $this->respondDeleteResult($result);
    }
}
