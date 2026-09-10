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

namespace Dou\Admin\Controller\Ai;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Ai\ApplicationFormRequest;
use Dou\Admin\Service\Ai\ApplicationService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Support\Arr;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 应用（route=ai/...）
 */
class AiController extends BaseController
{
    /** @var ApplicationService */
    private $aiService;

    /**
     * @param ApplicationService $aiService
     */
    public function __construct(ApplicationService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'link_user_center' => $this->buildLinkUserCenter('ai'),
            'cur' => 'ai',
            'submenu' => 'ai',
        );
    }

    /**
     * 应用列表（route=ai）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $placement = trim((string) $request->input('placement', ''));
        $taskType = trim((string) $request->input('task_type', ''));
        $statusIn = $request->input('status', '');
        $statusRaw = is_scalar($statusIn) ? trim((string) $statusIn) : '';
        $page = $request->integer('page', 1);

        $req = array(
            'placement' => $placement,
            'task_type' => $taskType,
            'status' => $statusRaw,
            'page' => $page,
        );

        $bundle = $this->aiService->buildAiListData($placement, $taskType, $statusRaw, $page);

        return $this->view('ai.htm', [
            'ur_here' => lang('ai'),
            'page_actions' => array(
                array('href' => route('admin.ai.create'), 'text' => lang('ai_create'), 'style' => 'add'),
            ),
            'rec' => 'default',
            'req' => $req,
            'ai_list' => $bundle['ai_list'],
            'pager' => $bundle['pager'],
        ]);
    }

    /**
     * 添加页（route=ai/create）
     *
     * @return Response
     */
    public function create()
    {
        $form = $this->aiService->buildAiDefaultData();

        return $this->view('ai.htm', [
            'ur_here' => lang('ai_create'),
            'page_actions' => array(
                array('href' => route('admin.ai'), 'text' => lang('ai_list'), 'style' => ''),
            ),
            'rec' => 'create',
            'ai' => $form['ai'],
            'model_group_list' => $form['model_group_list'],
            'module_list' => $form['module_list'],
            'show_advanced' => $form['show_advanced'],
        ]);
    }

    /**
     * 保存新增（route=ai/store）
     *
     * @param ApplicationFormRequest $formRequest
     * @return Response
     */
    public function store(ApplicationFormRequest $formRequest)
    {
        $data = $formRequest->validated();

        $newId = $this->aiService->insert($data);

        return redirect(route('admin.ai.edit', array('id' => $newId)))
            ->with('success', lang('ai_add_succes'), route('admin.ai'), lang('back_to_list'));
    }

    /**
     * 编辑页（route=ai/edit）
     *
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.ai'));
        }

        $form = $this->aiService->buildAiEditData($id);
        if (!$form) {
            throw new DomainException(lang('illegal'), route('admin.ai'));
        }

        return $this->view('ai.htm', [
            'ur_here' => lang('ai_edit'),
            'page_actions' => array(
                array('href' => route('admin.ai'), 'text' => lang('ai_list'), 'style' => ''),
            ),
            'rec' => 'edit',
            'ai' => $form['ai'],
            'model_group_list' => $form['model_group_list'],
            'module_list' => $form['module_list'],
            'show_advanced' => $form['show_advanced'],
        ]);
    }

    /**
     * 保存编辑（route=ai/update）
     *
     * @param ApplicationFormRequest $formRequest
     * @return Response
     */
    public function update(ApplicationFormRequest $formRequest)
    {
        $data = $formRequest->validated();

        $this->aiService->update($data);
        $editId = (int) Arr::get($data, 'id', 0);

        return redirect(route('admin.ai.edit', array('id' => $editId)))
            ->with('success', lang('ai_edit_succes'), route('admin.ai'), lang('back_to_list'));
    }

    /**
     * 删除（route=ai/del）
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.ai'));
        }

        $result = $this->aiService->delete($id, $request->post());
        return $this->respondDeleteResult($result);
    }

    /**
     * 模块字段列表（route=ai/get_fields）
     *
     * @param Request $request
     * @return void
     */
    public function getFields(Request $request)
    {
        $fieldsRaw = trim((string) $request->input('fields', ''));
        $selectedFields = $fieldsRaw === '' ? array() : explode(',', $fieldsRaw);

        $this->aiService->sendModuleFieldsJson(
            $request->input('module'),
            $selectedFields
        );
    }

    /**
     * 批量操作（route=ai/action）
     *
     * @param Request $request
     * @return Response
     */
    public function action(Request $request)
    {
        $this->aiService->action($request->post());
        return redirect(route('admin.ai'))->with('success', lang('del_succes'));
    }
}
