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
use Dou\Admin\Request\Aigc\PackageFormRequest;
use Dou\Admin\Service\Aigc\PackageService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Support\Arr;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 套餐（route=aigc/package/...）
 */
class PackageController extends BaseController
{
    /** @var PackageService */
    private $packageService;

    /**
     * @param PackageService $packageService
     */
    public function __construct(PackageService $packageService)
    {
        $this->packageService = $packageService;
    }

    /**
     * 套餐列表（route=chat/package）
     *
     * @return Response
     */
    public function index()
    {
        $bundle = $this->packageService->getAdminIndexPageData();

        return $this->view('aigc_package.htm', [
            'ur_here' => lang('aigc_package'),
            'page_actions' => array(
                array('href' => route('admin.aigc.package.create'), 'text' => lang('aigc_package_create'), 'style' => 'add'),
            ),
            'rec' => 'default',
            'package_list' => $bundle['package_list'],
        ]);
    }

    /**
     * 添加页（route=chat/package/create）
     *
     * @return Response
     */
    public function create()
    {
        $form = $this->packageService->getAddFormData();

        return $this->view('aigc_package.htm', [
            'ur_here' => lang('aigc_package_create'),
            'page_actions' => array(
                array('href' => route('admin.aigc.package'), 'text' => lang('aigc_package'), 'style' => ''),
            ),
            'rec' => 'create',
            'package' => array(),
        ]);
    }

    /**
     * 保存新增（route=chat/package/store）
     *
     * @param PackageFormRequest $formRequest
     * @return Response
     */
    public function store(PackageFormRequest $formRequest)
    {
        $data = $formRequest->validated();

        $newId = $this->packageService->insert($data);

        return redirect(route('admin.aigc.package.edit', array('id' => $newId)))
            ->with('success', lang('aigc_package_add_succes'), route('admin.aigc.package'), lang('back_to_list'));
    }

    /**
     * 编辑页（route=chat/package/edit）
     *
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.aigc.package'));
        }

        $form = $this->packageService->getEditFormData($id);
        if (!$form) {
            throw new DomainException(lang('illegal'), route('admin.aigc.package'));
        }

        return $this->view('aigc_package.htm', [
            'ur_here' => lang('aigc_package_edit'),
            'page_actions' => array(
                array('href' => route('admin.aigc.package'), 'text' => lang('aigc_package'), 'style' => ''),
            ),
            'rec' => 'edit',
            'package' => $form['package'],
        ]);
    }

    /**
     * 保存编辑（route=chat/package/update）
     *
     * @param PackageFormRequest $formRequest
     * @return Response
     */
    public function update(PackageFormRequest $formRequest)
    {
        $data = $formRequest->validated();

        $this->packageService->update($data);
        $editId = (int) Arr::get($data, 'id', 0);

        return redirect(route('admin.aigc.package.edit', array('id' => $editId)))
            ->with('success', lang('aigc_package_edit_succes'), route('admin.aigc.package'), lang('back_to_list'));
    }

    /**
     * 删除（route=chat/package/delete）
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.aigc.package'));
        }

        $result = $this->packageService->delete($id, $request->post());
        return $this->respondDeleteResult($result);
    }

    /**
     * 批量操作（route=chat/package/batch）
     *
     * @param Request $request
     * @return Response
     */
    public function batch(Request $request)
    {
        $result = $this->packageService->batch($request->post());

        return redirect($result['back_url'])->with('success', $result['message']);
    }
}
