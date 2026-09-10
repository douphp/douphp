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

namespace Dou\Admin\Controller\Parameter;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Parameter\ParameterDeleteFormRequest;
use Dou\Admin\Request\Parameter\ParameterFormRequest;
use Dou\Admin\Request\Parameter\ParameterSetFormRequest;
use Dou\Admin\Service\Parameter\ParameterService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 自定义参数
 *
 * 资源 CRUD：{@see create()} / {@see store()} / {@see edit()} / {@see update()}（对照 ArticleController）。
 * 参数值批量保存：{@see save()}（独立 POST，不与 store 混用）。
 */
class ParameterController extends BaseController
{
    /** @var ParameterService */
    private $parameterService;

    /**
     * @param ParameterService $parameterService
     */
    public function __construct(ParameterService $parameterService)
    {
        $this->parameterService = $parameterService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'parameter',
        );
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $group = $request->alpha('group');

        $listData = $this->parameterService->buildParameterListData($group);

        return $this->view('parameter.htm', [
            'ur_here' => lang('parameter_list'),
            'page_breadcrumb' => lang('setting_developer'),
            'page_actions' => array(
                array('href' => route('admin.setting', array(), array('query' => array('dou' => ''))), 'text' => lang('setting_developer'), 'style' => ''),
            ),
            'page_cue' => '<em>' . lang('setting_developer_cue') . '</em>',
            'rec' => 'default',
            'parameter_list' => $listData['parameter_list'],
        ]);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function create(Request $request)
    {
        $group = $request->alpha('group');

        $defaultData = $this->parameterService->buildParameterDefaultData($group);

        return $this->view('parameter.htm', [
            'ur_here' => lang('parameter_create'),
            'page_breadcrumb' => lang('setting_developer'),
            'page_actions' => array(
                array('href' => route('admin.setting', array(), array('query' => array('dou' => ''))), 'text' => lang('setting_developer'), 'style' => ''),
            ),
            'page_cue' => '<em>' . lang('setting_developer_cue') . '</em>',
            'rec' => 'create',
            'group' => $defaultData['group'],
            'parameter' => $defaultData['parameter'],
            'parameter_help' => lang('parameter_help'),
        ]);
    }

    /**
     * @param ParameterFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(ParameterFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $result = $this->parameterService->insert($data);
        return redirect(route('admin.parameter.edit', array('id' => $result['new_id']))['new_id'])
            ->with('success', lang('parameter_create') . lang('success'), $result['back_url'], lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.parameter'));
        }

        $bundle = $this->parameterService->buildParameterEditData($id);
        if ($bundle === null) {
            throw new DomainException(lang('illegal'), route('admin.parameter'));
        }

        return $this->view('parameter.htm', [
            'ur_here' => lang('parameter_edit'),
            'page_breadcrumb' => lang('setting_developer'),
            'page_actions' => array(
                array('href' => route('admin.setting', array(), array('query' => array('dou' => ''))), 'text' => lang('setting_developer'), 'style' => ''),
            ),
            'page_cue' => '<em>' . lang('setting_developer_cue') . '</em>',
            'rec' => 'edit',
            'parameter' => $bundle['parameter'],
            'parameter_help' => $bundle['parameter_help'],
            'group' => isset($bundle['parameter']['group']) ? $bundle['parameter']['group'] : '',
        ]);
    }

    /**
     * @param ParameterFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(ParameterFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $this->parameterService->update($data);
        return redirect(route('admin.parameter.edit', array('id' => (int) $data['id'])))
            ->with('success', lang('parameter_edit') . lang('success'), route('admin.parameter'), lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function set(Request $request)
    {
        $group = $request->alpha('group');

        $groupTitle = '';
        if ($group !== '') {
            $groupTitle = ((lang($group) !== '')) ? lang($group) : $group;
        }

        $setData = $this->parameterService->buildParameterSetData($group);

        return $this->view('parameter.htm', [
            'ur_here' => $groupTitle . lang('parameter_value'),
            'page_actions' => array(
                array('href' => route('admin.setting'), 'text' => lang('setting'), 'style' => 'add'),
            ),
            'rec' => 'set',
            'parameter_list' => $setData['parameter_list'],
            'group' => $group,
        ]);
    }

    /**
     * 参数值批量保存（route=parameter/save）。
     *
     * @param ParameterSetFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function save(ParameterSetFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $backUrl = $this->parameterService->saveValues($data);
        return redirect($backUrl)->with('success', lang('edit_succes'));
    }

    /**
     * @param ParameterDeleteFormRequest $formRequest
     * @return Response
     */
    public function destroy(ParameterDeleteFormRequest $formRequest)
    {
        $data = $formRequest->validated();

        $result = $this->parameterService->deleteByIdOrConfirm((int) $data['id'], $data);

        if (!empty($result['redirect'])) {
            return redirect($result['back_url']);
        }

        return $this->respondDeleteResult($result);
    }
}
