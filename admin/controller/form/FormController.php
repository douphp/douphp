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
use Dou\Admin\Request\Form\FormFormRequest;
use Dou\Admin\Service\Form\FormService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台表单主信息（route=form/...）
 *
 * 视图分工（同目录另有字段与提交数据子控制器）：
 *   - form_template.htm：rec 无条件，预置模板引导页（空白表单 + 一键使用）
 *   - form.htm        ：rec=list 表单管理列表 / rec=create 新增 / rec=edit 编辑主信息
 *
 * 「添加表单」先落到引导页，一键使用后直接进字段列表（建完表单的下一步就是加字段）。
 */
class FormController extends BaseController
{
    /** @var FormService */
    private $formService;

    /**
     * @param FormService $formService
     */
    public function __construct(FormService $formService)
    {
        $this->formService = $formService;
    }

    /**
     * 入口：落到排序最靠前的表单的提交数据列表；无表单时先建表单。
     *
     * 数据列表不再提供「全部表单」汇总视图，因此入口必须带 form_id。
     *
     * @return Response
     */
    public function index()
    {
        $formId = $this->formService->getFirstFormId();
        if ($formId <= 0) {
            return redirect(route('admin.form.form_list'));
        }

        return redirect(route('admin.form.data', array('form_id' => $formId)));
    }

    /**
     * 表单管理列表。
     *
     * @param Request $request
     * @return Response
     */
    public function formList(Request $request)
    {
        $keyword = trim((string) $request->input('keyword', ''));
        $page = $request->integer('page', 1);

        $bundle = $this->formService->buildFormListPage($keyword, $page);

        return $this->view('form.htm', array(
            'rec' => 'list',
            'ur_here' => lang('form_list'),
            'page_actions' => array(
                array('href' => route('admin.form.template'), 'text' => lang('form_create'), 'style' => ''),
            ),
            'keyword' => $bundle['keyword'],
            'form_list' => $bundle['form_list'],
            'form_nav_list' => $this->formService->buildFormNavList(0),
            'form_manage_cur' => true,
            'pager' => $bundle['pager'],
        ));
    }

    /**
     * 预置模板引导页：空白表单 + 一键使用模板。
     *
     * @return Response
     */
    public function template()
    {
        return $this->view('form_template.htm', array(
            'ur_here' => lang('form_template_title'),
            'page_cue' => lang('form_template_intro'),
            'page_actions' => array(
                array('href' => route('admin.form.form_list'), 'text' => lang('form_list'), 'style' => ''),
            ),
            'template_cards' => $this->formService->buildTemplateCards(),
            'form_nav_list' => $this->formService->buildFormNavList(-1),
            // 新建流程（模板引导页 / 空白新增页）无对应表单，高亮落在子菜单的「表单管理」上
            'form_manage_cur' => true,
        ));
    }

    /**
     * 一键使用预置模板：建表单 + 播种整套字段，随后直接进字段列表。
     *
     * @param Request $request
     * @return Response
     */
    public function templateStore(Request $request)
    {
        $templateKey = trim((string) $request->input('template', ''));
        $formId = $this->formService->createFromTemplate($templateKey);
        $name = $this->formService->getFormName($formId);

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CREATE, 1, (string) $name);

        return redirect(route('admin.form.field', array('form_id' => $formId)))
            ->with(
                'success',
                preg_replace('/d%/Ums', $name, lang('form_template_created')),
                route('admin.form.form_list'),
                lang('back_to_list')
            );
    }

    /**
     * 新增空白表单页（字段留到字段页维护）。
     *
     * @return Response
     */
    public function create()
    {
        return $this->view('form.htm', array(
            'rec' => 'create',
            'ur_here' => lang('form_create'),
            'page_actions' => array(
                array('href' => route('admin.form.template'), 'text' => lang('form_template_title'), 'style' => ''),
                array('href' => route('admin.form.form_list'), 'text' => lang('form_list'), 'style' => ''),
            ),
            'form' => $this->formService->buildFormDefaultData(),
            'form_nav_list' => $this->formService->buildFormNavList(-1),
            'form_manage_cur' => true,
        ));
    }

    /**
     * 新增提交。
     *
     * @param FormFormRequest $formRequest
     * @return Response
     */
    public function store(FormFormRequest $formRequest)
    {
        $data = $formRequest->validated();
        $newId = $this->formService->insert($data);

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CREATE, 1, (string) $data['name']);

        return redirect(route('admin.form.field', array('form_id' => $newId)))
            ->with('success', lang('form_create_success'), route('admin.form.form_list'), lang('back_to_list'));
    }

    /**
     * 编辑表单主信息（字段与提交数据各有独立页面）。
     *
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $formId = (int) $request->route('id', 0);
        if ($formId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.form.form_list'));
        }

        $formInfo = $this->formService->findFormForEdit($formId);
        if (empty($formInfo)) {
            throw new DomainException(lang('illegal'), route('admin.form.form_list'));
        }

        return $this->view('form.htm', array(
            'rec' => 'edit',
            'ur_here' => lang('form_edit'),
            'page_actions' => array(
                array('href' => route('admin.form.form_list'), 'text' => lang('form_list'), 'style' => ''),
            ),
            'form' => $formInfo,
            // 表单信息编辑属于「表单管理」域，子菜单高亮落在「表单管理」而非具体表单上
            'form_nav_list' => $this->formService->buildFormNavList(0),
            'form_manage_cur' => true,
        ));
    }

    /**
     * 更新提交。
     *
     * @param FormFormRequest $formRequest
     * @return Response
     */
    public function update(FormFormRequest $formRequest)
    {
        $data = $formRequest->validated();
        $formId = (int) $data['form_id'];

        $this->formService->update($formId, $data);

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $data['name']);

        return redirect(route('admin.form.edit', array('id' => $formId)))
            ->with('success', lang('form_update_success'), route('admin.form.form_list'), lang('back_to_list'));
    }

    /**
     * 删除表单（连带字段与提交数据）。
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $formId = $request->integer('id', 0);
        if ($formId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.form.form_list'));
        }

        $name = $this->formService->getFormName($formId);

        if ($request->post('confirm', '')) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $name);
            $this->formService->delete($formId);

            return redirect(route('admin.form.form_list'));
        }

        $msg = preg_replace('/d%/Ums', $name, lang('del_check'));

        return message()->respond($msg, route('admin.form.form_list'), '', '30', route('admin.form.destroy', array('id' => $formId)), '', 'DELETE');
    }
}
