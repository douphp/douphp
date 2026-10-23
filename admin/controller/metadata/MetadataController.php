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

namespace Dou\Admin\Controller\Metadata;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Metadata\MetadataFormRequest;
use Dou\Admin\Service\Metadata\MetadataAdminService;
use Dou\Admin\Service\Setting\SettingService;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 扩展字段定义中央管理：统一列表页 + 独立新建 / 编辑页。
 */
class MetadataController extends BaseController
{
    /** @var MetadataAdminService */
    private $metadataAdminService;

    /** @var SettingService */
    private $settingService;

    /**
     * @param MetadataAdminService $metadataAdminService
     * @param SettingService $settingService
     */
    public function __construct(MetadataAdminService $metadataAdminService, SettingService $settingService)
    {
        $this->metadataAdminService = $metadataAdminService;
        $this->settingService = $settingService;
    }

    /**
     * 字段总览（资源 index，?route=metadata）。
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        return $this->renderList($request);
    }

    /**
     * 兼容旧入口 URL：?route=metadata/fields（后台菜单 link 保持该名称）。
     *
     * @param Request $request
     * @return Response
     */
    public function fields(Request $request)
    {
        return $this->renderList($request);
    }

    /**
     * 新建表单（?route=metadata/create&module=xxx，所属模块由列表分组入口带入且不可改）。
     *
     * @param Request $request
     * @return Response
     */
    public function create(Request $request)
    {
        $moduleInput = $request->input('module', '');
        $data = $this->metadataAdminService->buildFormData(0, is_scalar($moduleInput) ? (string) $moduleInput : '');

        return $this->renderForm(
            $data,
            lang('metadata_create', '新增扩展字段'),
            route('admin.metadata.fields')
        );
    }

    /**
     * 编辑表单（?route=metadata/{id}/edit）。
     *
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $data = $this->metadataAdminService->buildFormData($request->integer('id', 0));

        return $this->renderForm(
            $data,
            lang('metadata_edit', '编辑扩展字段'),
            route('admin.metadata.fields', array('module' => $data['field']['module']))
        );
    }

    /**
     * 提交新增（POST ?route=metadata，form_action 由 FormTargetBinder 装配）。
     *
     * @param MetadataFormRequest $formRequest
     * @return Response
     */
    public function store(MetadataFormRequest $formRequest)
    {
        $result = $this->metadataAdminService->save($formRequest->validated());

        return redirect($this->listUrl($result['module']))
            ->with('success', lang('metadata_create_success', '扩展字段已创建'));
    }

    /**
     * 提交更新（PUT ?route=metadata/{id}）。
     *
     * @param MetadataFormRequest $formRequest
     * @return Response
     */
    public function update(MetadataFormRequest $formRequest)
    {
        $result = $this->metadataAdminService->save($formRequest->validated());

        return redirect($this->listUrl($result['module']))
            ->with('success', lang('metadata_update_success', '扩展字段已保存'));
    }

    /**
     * 删除（DELETE ?route=metadata/{id}，经 #dou-delete-form 方法伪装提交）。
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $module = $this->metadataAdminService->delete($request->integer('id', 0));

        return redirect($this->listUrl($module))
            ->with('success', lang('metadata_delete_success', '扩展字段已删除'));
    }

    /**
     * 列表页地址（携带模块锚点，回列表时定位到对应分组）。
     *
     * @param string $module
     * @return string
     */
    private function listUrl($module)
    {
        $url = route('admin.metadata.fields');
        $module = (string) $module;

        return $module === '' ? $url : $url . '#module-' . $module;
    }

    /**
     * 列表页渲染。
     *
     * @param Request $request
     * @return Response
     */
    private function renderList(Request $request)
    {
        $data = $this->metadataAdminService->buildListData();

        return $this->view('metadata.htm', array(
            'ur_here' => lang('metadata', '扩展字段'),
            'rec' => 'default',
            'groups' => $data['groups'],
            'page_cue' => $data['page_cue'],
        ) + $this->settingService->buildTabNavContext());
    }

    /**
     * 表单页渲染（与列表页共用设置体系 Tab，新增 / 编辑都显示）。
     *
     * @param array $data buildFormData() 结果
     * @param string $urHere 页面标题
     * @param string $backUrl 返回列表地址
     * @return Response
     */
    private function renderForm(array $data, $urHere, $backUrl)
    {
        return $this->view('metadata.htm', array(
            'ur_here' => $urHere,
            'rec' => 'form',
            'page_actions' => array(
                array('href' => $backUrl, 'text' => lang('metadata_list_title', '扩展字段'), 'style' => ''),
            ),
            'back_url' => $backUrl,
            'field' => $data['field'],
            'module_label' => $data['module_label'],
            'type_options' => $data['type_options'],
            'type_label' => $data['type_label'],
            'is_edit' => $data['is_edit'],
            'btn_lang' => isset($data['btn_lang']) ? $data['btn_lang'] : array(),
            'definition_lang_drafts' => isset($data['definition_lang_drafts']) ? $data['definition_lang_drafts'] : array(),
            'extensions_baseline' => isset($data['extensions_baseline']) ? $data['extensions_baseline'] : '',
            'page_cue' => $data['page_cue'],
        ) + $this->settingService->buildTabNavContext());
    }
}
