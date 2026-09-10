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

namespace Dou\Admin\Controller\Miniprogram;

use Dou\Admin\Controller\BaseController;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Admin\Request\Miniprogram\MiniprogramNavFormRequest;
use Dou\Admin\Service\Miniprogram\MiniprogramNavService;
use Dou\Admin\Service\Miniprogram\MiniprogramService;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序导航（route=miniprogram/nav/...）
 */
class NavController extends BaseController
{
    /** @var MiniprogramNavService */
    private $miniprogramNavService;

    /**
     * @param MiniprogramNavService $miniprogramNavService
     */
    public function __construct(MiniprogramNavService $miniprogramNavService)
    {
        $this->miniprogramNavService = $miniprogramNavService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'miniprogram',
        );
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $type = $request->rec('type', 'miniprogram_top');

        $listData = $this->miniprogramNavService->buildMiniprogramNavListData($type);
        return $this->view('miniprogram.htm', [
            'ur_here' => lang('miniprogram_nav'),
            'page_actions' => array(
                array('href' => route('admin.miniprogram.nav.create', array('type' => $type)), 'text' => lang('nav_create'), 'style' => ''),
            ),
            'rec' => 'nav',
            'act' => 'default',
            'type' => $type,
            'type_name' => lang('miniprogram_nav_' . $type),
            'nav_list' => $listData['nav_list'],
            'sub_cur' => (SYSTEM_SIGN == 'api') ? 'miniprogram_nav' : '',
        ]);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function create(Request $request)
    {
        $type = $request->rec('type', 'miniprogram_top');

        $this->miniprogramNavService->buildMiniprogramNavCreateData($type);

        lang_set('order', '购物车');
        return $this->view('miniprogram.htm', [
            'ur_here' => lang('miniprogram_nav'),
            'page_actions' => array(
                array('href' => route('admin.miniprogram.nav', array('type' => $type)), 'text' => lang('nav_list'), 'style' => ''),
            ),
            'rec' => 'nav',
            'act' => 'add',
            'type' => $type,
            'type_name' => lang('miniprogram_nav_' . $type),
            'catalog_list' => $this->miniprogramNavService->buildNavCatalogDefaultList(),
            'btn_lang' => language()->buildLangButtons('nav', '', 'name, guide'),
            'nav_info' => array('icon' => ''),
            'sub_cur' => (SYSTEM_SIGN == 'api') ? 'miniprogram_nav' : '',
        ]);
    }

    /**
     * @param MiniprogramNavFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(MiniprogramNavFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $newId = $this->miniprogramNavService->storeNav($data);
        $type = isset($data['type']) ? $data['type'] : 'miniprogram_top';
        $backUrl = route('admin.miniprogram.nav', array('type' => $type));
        return redirect(route('admin.miniprogram.nav.edit', array('id' => $newId, 'type' => $type)))
            ->with('success', lang('nav_add_succes'), $backUrl, lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id', 0);
        $typeQuery = $request->rec('type');

        $editData = $this->miniprogramNavService->buildMiniprogramNavEditData($id, $typeQuery);
        if ($editData === null) {
            throw new DomainException(lang('illegal'), route('admin.miniprogram.nav'));
        }
        $nav_info = $editData['nav_info'];
        $type = $editData['type'];

        lang_set('order', '购物车');
        return $this->view('miniprogram.htm', [
            'ur_here' => lang('miniprogram_nav'),
            'page_actions' => array(
                array('href' => route('admin.miniprogram.nav', array('type' => $type)), 'text' => lang('nav_list'), 'style' => ''),
            ),
            'rec' => 'nav',
            'act' => 'edit',
            'type' => $type,
            'type_name' => lang('miniprogram_nav_' . $type),
            'catalog_list' => $editData['catalog_list'],
            'nav_info' => $nav_info,
            'sub_cur' => (SYSTEM_SIGN == 'api') ? 'miniprogram_nav' : '',
        ]);
    }

    /**
     * @param MiniprogramNavFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(MiniprogramNavFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $this->miniprogramNavService->updateNav($data);
        $type = isset($data['type']) ? $data['type'] : 'miniprogram_top';
        $backUrl = route('admin.miniprogram.nav', array('type' => $type));
        return redirect(route('admin.miniprogram.nav.edit', array('id' => (int)$data['id'], 'type' => $type)))
            ->with('success', lang('nav_edit_succes'), $backUrl, lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.miniprogram.nav'));
        }

        $result = $this->miniprogramNavService->deleteNav($id, $request->post());
        return $this->respondDeleteResult($result);
    }
}
