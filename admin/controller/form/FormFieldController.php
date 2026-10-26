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

namespace Dou\Admin\Controller\Form;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Form\FormFieldFormRequest;
use Dou\Admin\Service\Form\FormFieldService;
use Dou\Admin\Service\Form\FormService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台表单字段（route=form/field/...）
 *
 * 与表单主信息编辑彻底分开：本控制器只渲染 form_field.htm（rec=list 字段列表 /
 * rec=create 新增 / rec=edit 编辑），表单 meta 由 FormService::findFormForEdit() 取一份。
 */
class FormFieldController extends BaseController
{
    /** @var FormService */
    private $formService;

    /** @var FormFieldService */
    private $fieldService;

    /**
     * @param FormService $formService
     * @param FormFieldService $fieldService
     */
    public function __construct(FormService $formService, FormFieldService $fieldService)
    {
        $this->formService = $formService;
        $this->fieldService = $fieldService;
    }

    /**
     * 字段列表 + 字段新增 / 编辑（同一页面）。
     *
     * 参考原 form/edit 的左列表右表单形态：`field_id` 缺省或不属于当前表单时，
     * 右侧为新增表单；给出且归属当前表单时回填该字段。
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $formId = $request->integer('form_id', 0);
        $formInfo = $formId > 0 ? $this->formService->findFormForEdit($formId) : null;
        if (empty($formInfo)) {
            return redirect(route('admin.form.form_list'));
        }

        $fieldId = $request->integer('field_id', 0);

        return $this->view('form_field.htm', array(
            'ur_here' => lang('form_field_list'),
            'page_cue' => preg_replace('/d%/Ums', $formInfo['name'], lang('form_field_list_cue')),
            'page_actions' => array(
                array('href' => route('admin.form.edit', array('id' => $formId)), 'text' => lang('form'), 'style' => ''),
                array('href' => $formInfo['data_url'], 'text' => lang('form_data_list'), 'style' => ''),
            ),
            'form' => $formInfo,
            'field_list' => $this->fieldService->buildFieldList($formId),
            'form_nav_list' => $this->formService->buildFormNavList($formId),
        ) + $this->fieldService->buildFieldPanelAssigns($formId, $fieldId));
    }

    /**
     * 新增提交。
     *
     * @param FormFieldFormRequest $formRequest
     * @return Response
     */
    public function store(FormFieldFormRequest $formRequest)
    {
        $data = $formRequest->validated();
        $this->fieldService->insertField($data);

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CREATE, 1, 'form_field:' . $data['name']);

        return redirect(route('admin.form.field', array('form_id' => (int) $data['form_id'])))
            ->with('success', lang('form_field_create_success'));
    }

    /**
     * 更新提交。
     *
     * @param FormFieldFormRequest $formRequest
     * @return Response
     */
    public function update(FormFieldFormRequest $formRequest)
    {
        $data = $formRequest->validated();
        $formId = $this->fieldService->updateField($data);

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, 'form_field:' . $data['name']);

        return redirect(route('admin.form.field', array('form_id' => $formId, 'field_id' => (int) $data['id'])))
            ->with('success', lang('form_field_update_success'));
    }

    /**
     * 删除字段。
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        $formId = $request->integer('form_id', 0);
        if ($formId <= 0) {
            $formId = $id > 0 ? $this->fieldService->getFieldFormId($id) : 0;
        }
        if ($formId <= 0 || $id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.form.form_list'));
        }

        $backUrl = route('admin.form.field', array('form_id' => $formId));
        $name = $this->fieldService->getFieldName($id);

        if ($request->post('confirm', '')) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'form_field:' . $name);
            $this->fieldService->deleteField($id);

            return redirect($backUrl)->with('success', lang('form_field_delete_success'));
        }

        $confirmUrl = route('admin.form.field.destroy', array('id' => $id, 'form_id' => $formId));
        $msg = preg_replace('/d%/Ums', $name, lang('del_check'));

        return message()->respond($msg, $backUrl, '', '30', $confirmUrl, '', 'DELETE');
    }
}
