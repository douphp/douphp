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
use Dou\Admin\Service\Form\FormDataService;
use Dou\Admin\Service\Form\FormService;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台表单提交数据（route=form/data/...）
 *
 * 同一个 form_data.htm 用 rec 区分：rec=list 列表（默认入口/子菜单切换表单）/ rec=detail 详情。
 */
class FormDataController extends BaseController
{
    /** @var FormService */
    private $formService;

    /** @var FormDataService */
    private $dataService;

    /**
     * @param FormService $formService
     * @param FormDataService $dataService
     */
    public function __construct(FormService $formService, FormDataService $dataService)
    {
        $this->formService = $formService;
        $this->dataService = $dataService;
    }

    /**
     * 提交数据列表（默认入口）。
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $formId = $request->integer('form_id', 0);
        $keyword = trim((string) $request->input('keyword', ''));
        $page = $request->integer('page', 1);

        // form_id 是必需参数：缺失或表单已不存在时落到第一个表单（无表单则去建表单）
        if ($formId <= 0 || DB::table('form')->where('id', $formId)->count() <= 0) {
            $firstFormId = $this->formService->getFirstFormId();
            if ($firstFormId <= 0) {
                return redirect(route('admin.form.form_list'));
            }

            return redirect(route('admin.form.data', array('form_id' => $firstFormId)));
        }

        $bundle = $this->dataService->buildFormDataListPage($formId, $keyword, $page);

        return $this->view('form_data.htm', array(
            'rec' => 'list',
            'ur_here' => lang('form_data_list'),
            // 顶部操作：数据列表页不放「添加表单」入口，新建表单统一走左侧子菜单的加号；
            // 「表单管理」也已在左侧子菜单固定提供，顶部不再重复；
            // 补充「编辑表单」直达入口，免去经字段列表再点「表单」的两跳
            'page_actions' => array(
                array('href' => route('admin.form.field', array('form_id' => $bundle['form_id'])), 'text' => lang('form_field_list'), 'style' => ''),
                array('href' => route('admin.form.edit', array('id' => $bundle['form_id'])), 'text' => lang('form_edit'), 'style' => ''),
            ),
            'form_id' => $bundle['form_id'],
            'keyword' => $bundle['keyword'],
            'field_list' => $bundle['field_list'],
            'col_count' => $bundle['col_count'],
            'form_data_list' => $bundle['form_data_list'],
            'form_nav_list' => $this->formService->buildFormNavList($bundle['form_id']),
            'mark_all_confirm' => $this->dataService->buildMarkAllConfirm($bundle['form_id']),
            'pager' => $bundle['pager'],
        ));
    }

    /**
     * 提交详情。
     *
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $formId = $request->integer('form_id', 0);
        $id = $request->integer('id', 0);

        $formData = $this->dataService->getFormDataForView($id);
        if (empty($formData)) {
            throw new DomainException(lang('illegal'), route('admin.form.data', array('form_id' => $formId)));
        }

        $backUrl = route('admin.form.data', array('form_id' => (int) $formData['form_id']));

        return $this->view('form_data.htm', array(
            'rec' => 'detail',
            'ur_here' => lang('form_data_view'),
            'page_actions' => array(
                array('href' => $backUrl, 'text' => lang('form_data_list'), 'style' => ''),
                array('href' => route('admin.form.edit', array('id' => (int) $formData['form_id'])), 'text' => lang('form_edit'), 'style' => ''),
                array(
                    'href' => route('admin.form.data.destroy', array('id' => (int) $formData['id'], 'form_id' => (int) $formData['form_id'])),
                    'text' => lang('del'),
                    'style' => '',
                    'delete' => true,
                ),
            ),
            'form_nav_list' => $this->formService->buildFormNavList((int) $formData['form_id']),
            'form_data' => $formData,
        ));
    }

    /**
     * 切换提交数据的处理状态。
     *
     * @param Request $request
     * @return Response
     */
    public function check(Request $request)
    {
        $id = $request->integer('id', 0);

        $status = $this->dataService->toggleFormDataStatus($id);

        $cls = $status ? 'd' : 'h';

        return $this->response('<em class="' . $cls . '"><b>' . lang('form_data_status_1') . '</b><s>' . lang('form_data_status_0') . '</s></em>');
    }

    /**
     * 全部标记为已读且已处理（作用范围跟随当前筛选的表单）。
     *
     * @param Request $request
     * @return Response
     */
    public function markAll(Request $request)
    {
        $formId = $request->integer('form_id', 0);

        $affected = $this->dataService->markAllFormDataProcessed($formId);

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, 'form_data:mark_all:' . $formId);

        return redirect(route('admin.form.data', array('form_id' => $formId)))
            ->with('success', preg_replace('/d%/Ums', $affected, lang('form_data_mark_all_success')));
    }

    /**
     * 删除提交数据。
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $formId = $request->integer('form_id', 0);
        $backUrl = route('admin.form.data', array('form_id' => $formId));
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), $backUrl);
        }

        if ($request->post('confirm', '')) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'form_data:' . $id);
            $this->dataService->deleteData($id);

            return redirect($backUrl);
        }

        $confirmUrl = route('admin.form.data.destroy', array('id' => $id, 'form_id' => $formId));
        $msg = preg_replace('/d%/Ums', $id, lang('del_check'));

        return message()->respond($msg, $backUrl, '', '30', $confirmUrl, '', 'DELETE');
    }
}
