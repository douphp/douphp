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

namespace Dou\Admin\Controller\Show;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Show\ShowFormRequest;
use Dou\Admin\Service\Show\ShowService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台幻灯
 *
 * 格式校验由 ShowFormRequest 完成；业务与删除确认由 Service / DomainException 处理。
 */
class ShowController extends BaseController
{
    /** @var ShowService */
    private $showService;

    /**
     * @param ShowService $showService
     */
    public function __construct(ShowService $showService)
    {
        $this->showService = $showService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'show',
        );
    }

    /**
     * 列表 + 左侧新增表单（单页）。
     *
     * @return Response
     */
    public function index()
    {
        $type = SYSTEM_SIGN == 'api' ? 'miniprogram' : 'pc';

        $listData = $this->showService->buildShowListData($type);

        return $this->view('show.htm', [
            'ur_here' => lang('show'),
            'page_cue' => '<p>' . lang('show_cue') . '</p>',
            'rec' => 'default',
            'show_list' => $listData['list'],
            'btn_lang' => language()->buildLangButtons('show', '', 'name, image, link, text'),
            'show' => $this->showService->buildShowDefaultData(),
            'id' => '',
        ]);
    }

    /**
     * 与 index 同页；不改变单页交互。
     *
     * @return void
     */
    public function create()
    {
        return $this->index();
    }

    /**
     * 提交新增
     *
     * @param ShowFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(ShowFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $type = SYSTEM_SIGN == 'api' ? 'miniprogram' : 'pc';

        $newId = $this->showService->insert($data, $type);

        return redirect(route('admin.show.edit', array('id' => $newId)))
            ->with('success', lang('show_add_succes'), route('admin.show'), lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $type = SYSTEM_SIGN == 'api' ? 'miniprogram' : 'pc';

        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.show'));
        }

        $show = $this->showService->buildShowEditData($id);
        if ($show === null || empty($show)) {
            throw new DomainException(lang('illegal'), route('admin.show'));
        }

        $listData = $this->showService->buildShowListData($type);

        return $this->view('show.htm', [
            'ur_here' => lang('show'),
            'page_cue' => '<p>' . lang('show_cue') . '</p>',
            'rec' => 'edit',
            'show_list' => $listData['list'],
            'id' => $id,
            'show' => $show,
            'btn_lang' => language()->buildLangButtons('show', $id, 'name, image, link, text'),
        ]);
    }

    /**
     * 提交更新
     *
     * @param ShowFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(ShowFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $this->showService->update($data);

        return redirect(route('admin.show.edit', array('id' => (int) $data['id'])))
            ->with('success', lang('show_edit_succes'), route('admin.show'), lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.show'));
        }

        $result = $this->showService->delete($id, $request->post());
        return $this->respondDeleteResult($result);
    }
}
