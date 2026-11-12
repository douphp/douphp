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
 * Release Date: 2026-11-11
 */

namespace Dou\Admin\Controller\Aigc;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Aigc\FieldFormRequest;
use Dou\Admin\Service\Aigc\FieldService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * AI应用表单字段（route=aigc/field/...）。
 */
class FieldController extends BaseController
{
    /** @var FieldService */
    private $fieldService;

    public function __construct(FieldService $fieldService)
    {
        $this->fieldService = $fieldService;
    }

    /**
     * 字段管理页（route=aigc/field?app_id=）：新增/编辑面板与字段列表同页。
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $appId = $request->integer('app_id', 0);
        $fieldId = $request->integer('field_id', 0);
        $bundle = $this->fieldService->getPageData($appId, $fieldId);

        return $this->view('aigc_field.htm', array(
            'ur_here' => lang('aigc_field_manage'),
            'page_actions' => array(
                array('href' => route('admin.aigc.field', array('app_id' => $appId)), 'text' => lang('aigc_field_create'), 'style' => 'add'),
                array('href' => route('admin.aigc.edit', array('id' => $appId)), 'text' => lang('back'), 'style' => ''),
            ),
            'app' => $bundle['app'],
            'field_list' => $bundle['field_list'],
            'field' => $bundle['field'],
            'type_options' => $bundle['type_options'],
            'form_action' => $bundle['form_action'],
            'form_method' => $bundle['form_method'],
            'app_id' => $appId,
        ));
    }

    /**
     * 保存新增。
     *
     * @param FieldFormRequest $formRequest
     * @return Response
     */
    public function store(FieldFormRequest $formRequest)
    {
        $data = $formRequest->validated();
        $this->fieldService->insert($data);

        return redirect(route('admin.aigc.field', array('app_id' => (int) $data['app_id'])))
            ->with('success', lang('aigc_field_add_succes'), route('admin.aigc.field', array('app_id' => (int) $data['app_id'])), lang('back_to_list'));
    }

    /**
     * 编辑字段（route=aigc/field/edit?id=&app_id=）
     *
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id', 0);
        $appId = $request->integer('app_id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.aigc.field', array('app_id' => $appId)));
        }
        $bundle = $this->fieldService->buildPanel($appId, $id);

        return $this->view('aigc_field.htm', array(
            'ur_here' => lang('aigc_field_edit'),
            'page_actions' => array(
                array('href' => route('admin.aigc.field', array('app_id' => $appId)), 'text' => lang('aigc_field_list'), 'style' => ''),
            ),
            'rec' => 'edit',
            'app' => $bundle['app'],
            'field' => $bundle['field'],
            'type_options' => $bundle['type_options'],
            'form_action' => $bundle['form_action'],
            'form_method' => $bundle['form_method'],
            'app_id' => $appId,
        ));
    }

    /**
     * 保存编辑。
     *
     * @param FieldFormRequest $formRequest
     * @return Response
     */
    public function update(FieldFormRequest $formRequest)
    {
        $data = $formRequest->validated();
        $this->fieldService->update($data);

        return redirect(route('admin.aigc.field', array('app_id' => (int) $data['app_id'])))
            ->with('success', lang('aigc_field_edit_succes'), route('admin.aigc.field', array('app_id' => (int) $data['app_id'])), lang('back_to_list'));
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
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.aigc'));
        }
        $result = $this->fieldService->delete($id, $request->post());

        return $this->respondDeleteResult($result);
    }
}
