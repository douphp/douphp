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

namespace Dou\Admin\Controller\Nav;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Nav\NavFormRequest;
use Dou\Admin\Service\Nav\NavService;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 主导航
 */
class NavController extends BaseController
{
    /** @var NavService */
    private $navService;

    /**
     * @param NavService $navService
     */
    public function __construct(NavService $navService)
    {
        $this->navService = $navService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'nav',
        );
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $type = $request->alpha('type', 'middle');
        $listData = $this->navService->buildNavListData($type);
        return $this->view('nav.htm', [
            'ur_here' => lang('nav'),
            'page_actions' => array(
                array('href' => route('admin.nav.create'), 'text' => lang('nav_create'), 'style' => ''),
            ),
            'rec' => 'default',
            'type' => $type,
            'nav_list' => $listData['nav_list'],
        ]);
    }

    /**
     * @return Response
     */
    public function create()
    {
        $defaultData = $this->navService->buildNavDefaultData();
        $listData = $this->navService->buildNavListData('middle');
        return $this->view('nav.htm', [
            'ur_here' => lang('nav'),
            'page_actions' => array(
                array('href' => route('admin.nav'), 'text' => lang('nav_list'), 'style' => ''),
            ),
            'rec' => 'create',
            'nav_info' => $defaultData['nav_info'],
            'catalog_list' => $this->navService->buildNavTargetList('', '', 'nav'),
            'nav_list' => $listData['nav_list'],
            'btn_lang' => language()->buildLangButtons('nav', '', 'name, guide'),
        ]);
    }

    /**
     * @param NavFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(NavFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $openIcon = (string) Config::get('site.open_icon', '');
        $newId = $this->navService->insert($data, $openIcon);

        $navType = isset($data['type']) && $data['type'] !== '' ? $data['type'] : 'middle';
        $backUrl = Util::normalizeQueryString(route('admin.nav', array('type' => $navType)));
        return redirect(route('admin.nav.edit', array('id' => $newId)))
            ->with('success', lang('nav_add_succes'), $backUrl, lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.nav'));
        }

        $navInfo = $this->navService->buildNavEditData($id);
        if ($navInfo === null) {
            throw new DomainException(lang('illegal'), route('admin.nav'));
        }

        $listData = $this->navService->buildNavListData($navInfo['type'], (string) $id);
        return $this->view('nav.htm', [
            'ur_here' => lang('nav'),
            'page_actions' => array(
                array('href' => route('admin.nav'), 'text' => lang('nav_list'), 'style' => ''),
            ),
            'rec' => 'edit',
            'catalog_list' => $this->navService->buildNavTargetList($navInfo['module'], $navInfo['guide'], 'nav'),
            'nav_list' => $listData['nav_list'],
            'nav_info' => $navInfo,
            'btn_lang' => language()->buildLangButtons('nav', $id, 'name, guide'),
        ]);
    }

    /**
     * @param NavFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(NavFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $openIcon = (string) Config::get('site.open_icon', '');
        $this->navService->update($data, $openIcon);

        $navType = isset($data['type']) && $data['type'] !== '' ? $data['type'] : 'middle';
        $backUrl = Util::normalizeQueryString(route('admin.nav', array('type' => $navType)));
        return redirect(route('admin.nav.edit', array('id' => (int) $data['id'])))
            ->with('success', lang('nav_edit_succes'), $backUrl, lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function navSelect(Request $request)
    {
        $type = $request->input('type') ? trim($request->input('type')) : 'middle';
        $id = trim((string) $request->input('id'));

        return $this->response($this->navService->buildNavParentSelectHtml($type, $id));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.nav'));
        }

        $result = $this->navService->delete($id, $request->post());

        return $this->respondDeleteResult($result);
    }
}
