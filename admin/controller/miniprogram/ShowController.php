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
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Admin\Request\Miniprogram\MiniprogramShowFormRequest;
use Dou\Admin\Service\Miniprogram\MiniprogramShowService;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序幻灯（route=miniprogram/show/...）
 */
class ShowController extends BaseController
{
    /** @var MiniprogramShowService */
    private $miniprogramShowService;

    /**
     * @param MiniprogramShowService $miniprogramShowService
     */
    public function __construct(MiniprogramShowService $miniprogramShowService)
    {
        $this->miniprogramShowService = $miniprogramShowService;
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

        $listData = $this->miniprogramShowService->buildMiniprogramShowListData();
        return $this->view('miniprogram.htm', [
            'ur_here' => lang('miniprogram_show'),
            'rec' => 'show',
            'act' => 'default',
            'show_list' => $listData['show_list'],
            'show' => $this->miniprogramShowService->buildMiniprogramShowDefaultForm(),
            'id' => '',
        ]);
    }

    /**
     * @param MiniprogramShowFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(MiniprogramShowFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $newId = $this->miniprogramShowService->storeShow($data);
        return redirect(route('admin.miniprogram.show.edit', array('id' => $newId)))
            ->with('success', lang('show_add_succes'), route('admin.miniprogram.show'), lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id', 0);
        $show = $this->miniprogramShowService->buildMiniprogramShowEditData($id);
        if ($show === null) {
            throw new DomainException(lang('illegal'), route('admin.miniprogram.show'));
        }

        $listData = $this->miniprogramShowService->buildMiniprogramShowListData();
        return $this->view('miniprogram.htm', [
            'ur_here' => lang('miniprogram_show'),
            'rec' => 'show',
            'act' => 'edit',
            'show_list' => $listData['show_list'],
            'id' => $id,
            'show' => $show,
        ]);
    }

    /**
     * @param MiniprogramShowFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(MiniprogramShowFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $this->miniprogramShowService->updateShow($data);
        return redirect(route('admin.miniprogram.show.edit', array('id' => (int)$data['id'])))
            ->with('success', lang('show_edit_succes'), route('admin.miniprogram.show'), lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.miniprogram.show'));
        }

        $result = $this->miniprogramShowService->deleteShow($id, $request->post());
        return $this->respondDeleteResult($result);
    }
}
