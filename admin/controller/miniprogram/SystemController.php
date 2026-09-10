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
use Dou\Admin\Request\Miniprogram\MiniprogramSystemFormRequest;
use Dou\Admin\Service\Miniprogram\MiniprogramService;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序参数（route=miniprogram/system/...）
 */
class SystemController extends BaseController
{
    /** @var MiniprogramService */
    private $miniprogramService;

    /**
     * @param MiniprogramService $miniprogramService
     */
    public function __construct(MiniprogramService $miniprogramService)
    {
        $this->miniprogramService = $miniprogramService;
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
        $data = $this->miniprogramService->buildMiniprogramSystemIndexData();

        return $this->view('miniprogram.htm', [
            'ur_here' => lang('miniprogram_system'),
            'rec' => 'system',
            'act' => 'default',
            'parameter_list' => $data['parameter_list'],
        ]);
    }

    /**
     * @param MiniprogramSystemFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(MiniprogramSystemFormRequest $formRequest, Request $request)
    {
        $validated = $formRequest->validated();
        $this->miniprogramService->updateMiniprogramParameters($validated);
        return redirect(route('admin.miniprogram.system'))->with('success', lang('edit_succes'));
    }
}
