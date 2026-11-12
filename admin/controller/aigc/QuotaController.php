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
use Dou\Admin\Request\Aigc\QuotaFormRequest;
use Dou\Admin\Service\Aigc\QuotaService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Support\Arr;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员配额（route=aigc/quota/...）
 */
class QuotaController extends BaseController
{
    /** @var QuotaService */
    private $quotaService;

    /**
     * @param QuotaService $quotaService
     */
    public function __construct(QuotaService $quotaService)
    {
        $this->quotaService = $quotaService;
    }

    /**
     * 配额列表（route=chat/quota）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $req = $request->all();
        $bundle = $this->quotaService->getAdminIndexPageData($req);

        return $this->view('aigc_quota.htm', [
            'ur_here' => lang('aigc_quota'),
            'page_actions' => array(
                array('href' => route('admin.aigc.quota.create'), 'text' => lang('aigc_quota_create'), 'style' => 'add'),
            ),
            'rec' => 'default',
            'req' => $req,
            'quota_list' => $bundle['quota_list'],
            'pager' => $bundle['pager'],
        ]);
    }

    /**
     * 添加页（route=chat/quota/create）
     *
     * @return Response
     */
    public function create()
    {
        $form = $this->quotaService->getAddFormData();

        return $this->view('aigc_quota.htm', [
            'ur_here' => lang('aigc_quota_create'),
            'page_actions' => array(
                array('href' => route('admin.aigc.quota'), 'text' => lang('aigc_quota'), 'style' => ''),
            ),
            'rec' => 'create',
            'quota' => $form['quota'],
        ]);
    }

    /**
     * 保存新增（route=chat/quota/store）
     *
     * @param QuotaFormRequest $formRequest
     * @return Response
     */
    public function store(QuotaFormRequest $formRequest)
    {
        $data = $formRequest->validated();

        $newId = $this->quotaService->insert($data);

        return redirect(route('admin.aigc.quota.edit', array('id' => $newId)))
            ->with('success', lang('aigc_quota_add_succes'), route('admin.aigc.quota'), lang('back_to_list'));
    }

    /**
     * 编辑页（route=chat/quota/edit）
     *
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.aigc.quota'));
        }

        $form = $this->quotaService->getEditFormData($id);
        if (!$form) {
            throw new DomainException(lang('illegal'), route('admin.aigc.quota'));
        }

        return $this->view('aigc_quota.htm', [
            'ur_here' => lang('aigc_quota_edit'),
            'page_actions' => array(
                array('href' => route('admin.aigc.quota'), 'text' => lang('aigc_quota'), 'style' => ''),
            ),
            'rec' => 'edit',
            'quota' => $form['quota'],
        ]);
    }

    /**
     * 保存编辑（route=chat/quota/update）
     *
     * @param QuotaFormRequest $formRequest
     * @return Response
     */
    public function update(QuotaFormRequest $formRequest)
    {
        $data = $formRequest->validated();

        $this->quotaService->update($data);
        $editId = (int) Arr::get($data, 'id', 0);

        return redirect(route('admin.aigc.quota.edit', array('id' => $editId)))
            ->with('success', lang('aigc_quota_edit_succes'), route('admin.aigc.quota'), lang('back_to_list'));
    }

    /**
     * 删除（route=chat/quota/delete）
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.aigc.quota'));
        }

        $result = $this->quotaService->delete($id, $request->post());
        return $this->respondDeleteResult($result);
    }

    /**
     * 批量操作（route=chat/quota/action）
     *
     * @param Request $request
     * @return Response
     */
    public function action(Request $request)
    {
        $result = $this->quotaService->action($request->post());

        return redirect($result['back_url'])->with('success', $result['message']);
    }
}
