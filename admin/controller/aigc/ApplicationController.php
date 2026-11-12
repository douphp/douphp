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

namespace Dou\Admin\Controller\Aigc;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Aigc\ApplicationFormRequest;
use Dou\Admin\Service\Aigc\ApplicationService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Support\Arr;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员 AI 应用（route=aigc/...）
 */
class ApplicationController extends BaseController
{
    /** @var ApplicationService */
    private $applicationService;

    /**
     * @param ApplicationService $applicationService
     */
    public function __construct(ApplicationService $applicationService)
    {
        $this->applicationService = $applicationService;
    }

    /**
     * 应用列表（route=aigc）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $type = trim((string) $request->input('type', ''));
        $statusIn = $request->input('status', '');
        $statusRaw = is_scalar($statusIn) ? trim((string) $statusIn) : '';
        $page = $request->integer('page', 1);

        $req = array(
            'type' => $type,
            'status' => $statusRaw,
            'page' => $page,
        );

        $bundle = $this->applicationService->buildAigcListData($type, $statusRaw, $page);

        return $this->view('aigc.htm', [
            'ur_here' => lang('aigc'),
            'page_actions' => array(
                array('href' => route('admin.aigc.create'), 'text' => lang('aigc_create'), 'style' => 'add'),
            ),
            'rec' => 'default',
            'req' => $req,
            'aigc_list' => $bundle['aigc_list'],
            'pager' => $bundle['pager'],
        ]);
    }

    /**
     * 添加页（route=aigc/create）
     *
     * @return Response
     */
    public function create()
    {
        $form = $this->applicationService->buildAigcDefaultData();

        return $this->view('aigc.htm', [
            'ur_here' => lang('aigc_create'),
            'page_actions' => array(
                array('href' => route('admin.aigc'), 'text' => lang('aigc_list'), 'style' => ''),
            ),
            'rec' => 'create',
            'aigc' => $form['aigc'],
            'model_group_list' => $form['model_group_list'],
            'suggested_questions' => $form['suggested_questions'],
        ]);
    }

    /**
     * 保存新增（route=aigc/store）
     *
     * @param ApplicationFormRequest $formRequest
     * @return Response
     */
    public function store(ApplicationFormRequest $formRequest)
    {
        $data = $formRequest->validated();

        $newId = $this->applicationService->insert($data);

        return redirect(route('admin.aigc.edit', array('id' => $newId)))
            ->with('success', lang('aigc_add_succes'), route('admin.aigc'), lang('back_to_list'));
    }

    /**
     * 编辑页（route=aigc/edit）
     *
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.aigc'));
        }

        $form = $this->applicationService->buildAigcEditData($id);
        if (!$form) {
            throw new DomainException(lang('illegal'), route('admin.aigc'));
        }

        return $this->view('aigc.htm', [
            'ur_here' => lang('aigc_edit'),
            'page_actions' => array(
                array('href' => route('admin.aigc'), 'text' => lang('aigc_list'), 'style' => ''),
                array('href' => route('admin.aigc.field', array('app_id' => $id)), 'text' => lang('aigc_field_manage'), 'style' => ''),
            ),
            'rec' => 'edit',
            'aigc' => $form['aigc'],
            'model_group_list' => $form['model_group_list'],
            'suggested_questions' => $form['suggested_questions'],
        ]);
    }

    /**
     * 保存编辑（route=aigc/update）
     *
     * @param ApplicationFormRequest $formRequest
     * @return Response
     */
    public function update(ApplicationFormRequest $formRequest)
    {
        $data = $formRequest->validated();

        $this->applicationService->update($data);
        $editId = (int) Arr::get($data, 'id', 0);

        return redirect(route('admin.aigc.edit', array('id' => $editId)))
            ->with('success', lang('aigc_edit_succes'), route('admin.aigc'), lang('back_to_list'));
    }

    /**
     * 删除（DELETE route=aigc/{id}）
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.aigc'));
        }

        $result = $this->applicationService->delete($id, $request->post());
        return $this->respondDeleteResult($result);
    }

    /**
     * 批量操作（route=aigc/action）
     *
     * @param Request $request
     * @return Response
     */
    public function action(Request $request)
    {
        $this->applicationService->action($request->post());
        return redirect(route('admin.aigc'))->with('success', lang('del_succes'));
    }
}
