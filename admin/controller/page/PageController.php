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

namespace Dou\Admin\Controller\Page;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Model\Page\Page;
use Dou\Admin\Request\Page\PageFormRequest;
use Dou\Admin\Service\Page\PageService;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台单页控制器
 *
 * RESTful 资源路由：create、store(PageFormRequest)、edit、update(PageFormRequest)；
 * 入库逻辑由 PageService::insert()/update() 承担。
 *
 * 格式校验由 PageFormRequest 完成（Action 注入按方法名绑定 scene），失败时抛出 DomainException；
 * 业务规则由 Service 内处理；统一由入口捕获 DomainException 并由 `$context->message->respond()` 输出。
 */
class PageController extends BaseController
{
    /** @var PageService */
    private $pageService;

    /**
     * @param PageService $pageService
     */
    public function __construct(PageService $pageService)
    {
        $this->pageService = $pageService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'page',
        );
    }

    /**
     * 单页列表（route=page）
     *
     * @return Response
     */
    public function index()
    {
        $listBundle = $this->pageService->buildPageListData();

        return $this->view('page.htm', [
            'ur_here' => lang('page_list'),
            'page_actions' => array(
                array('href' => route('admin.page.create'), 'text' => lang('page_create'), 'style' => ''),
            ),
            'rec' => 'default',
            'page_list' => $listBundle['page_list'],
        ]);
    }

    /**
     * 新增表单（route=page/create）
     *
     * @return Response
     */
    public function create()
    {
        $adminId = (int) auth('admin')->id();
        attachment()->cleanupUserDrafts('admin', $adminId, 'page');
        $draftToken = attachment()->newDraftToken('admin', $adminId);

        $bundle = $this->pageService->buildPageDefaultData();

        return $this->view('page.htm', [
            'ur_here' => lang('page_create'),
            'page_actions' => array(
                array('href' => route('admin.page'), 'text' => lang('page_list'), 'style' => ''),
            ),
            'rec' => 'create',
            'item_id' => 0,
            'draft_token' => $draftToken,
            'page_list' => Page::pageNolevel(),
            'page' => $bundle['page'],
            'item_content' => $bundle['item_content'],
            'download' => $this->pageService->buildPageDownloadData(0),
            'btn_lang' => language()->buildLangButtons('page', '', 'name, content, keywords, description'),
        ]);
    }

    /**
     * 提交新增（route=page/store）
     *
     * 字段白名单与校验规则定义在 PageFormRequest::rules()。
     * Action 注入会按方法名自动绑定 scene=store。
     *
     * @param PageFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(PageFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $draftToken = (string) $request->post('draft_token', '');
        $adminId = (int) auth('admin')->id();

        $newId = $this->pageService->insert($data, $draftToken, $adminId);
        return redirect(route('admin.page.edit', array('id' => $newId)))
            ->with('success', lang('page_add_succes'), route('admin.page'), lang('back_to_list'));
    }

    /**
     * 编辑表单（route=page/edit）
     *
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.page'));
        }

        $page = $this->pageService->buildPageEditData($id);
        if ($page === null) {
            throw new DomainException(lang('illegal'), route('admin.page'));
        }

        $dataList = data()->query('page', $page['slug']);

        $pageActions = array();
        if (Config::get('features.data', false)) {
            $pageActions[] = array(
                'href' => route('admin.data.create', array('group' => 'page', 'item' => $page['slug'])),
                'text' => lang('data_create'),
                'style' => 'add',
            );
        }
        $pageActions[] = array('href' => route('admin.page'), 'text' => lang('page_list'), 'style' => '');

        return $this->view('page.htm', [
            'ur_here' => lang('page_edit'),
            'page_actions' => $pageActions,
            'rec' => 'edit',
            'page_list' => Page::pageNolevel(0, 0, $id),
            'item_id' => $id,
            'draft_token' => '',
            'item_content' => $page['item_content'],
            'page' => $page,
            'download' => $this->pageService->buildPageDownloadData($id),
            'btn_lang' => language()->buildLangButtons('page', $id, 'name, content, keywords, description'),
            'data_list' => $dataList,
        ]);
    }

    /**
     * 提交更新（route=page/update；仅接受 POST；直接 GET 本地址会因无 token 触发非法操作并清空会话）
     *
     * 字段白名单与校验规则定义在 PageFormRequest::rules()。
     * Action 注入会按方法名自动绑定 scene=update。
     *
     * @param PageFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(PageFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $this->pageService->update($data, (int) auth('admin')->id());
        return redirect(route('admin.page.edit', array('id' => (int) $data['id'])))
            ->with('success', lang('page_edit_succes'), route('admin.page'), lang('back_to_list'));
    }

    /**
     * 可视化编辑保存（POST，route=page/visualize）
     *
     * 响应遵循 {@see ApiResponse} 五段包络：成功 throwSuccess()、参数非法 throwError('INVALID_PARAMS')。
     *
     * @param Request $request
     * @return void
     */
    public function visualize(Request $request)
    {
        $post = $request->post();
        if (!array_key_exists('content', $post)) {
            ApiResponse::throwError(ApiCodes::INVALID_PARAMS, lang('illegal'));
        }

        $id = $request->integer('id', 0);
        if ($id <= 0) {
            ApiResponse::throwError(ApiCodes::INVALID_PARAMS, lang('illegal'));
        }

        $pageName = $this->pageService->getPageName($id);

        $rawContent = $post['content'];
        $this->pageService->visualize($id, xss()->content($rawContent));
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, 'visualize:' . $pageName);
        ApiResponse::throwSuccess();
    }

    /**
     * 清空可视化内容（route=page/visualize_clear）
     *
     * @param Request $request
     * @return Response
     */
    public function visualizeClear(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.page'));
        }

        $pageName = $this->pageService->getPageName($id);

        $this->pageService->visualizeClear($id);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, 'visualize_clear:' . $pageName);
        return redirect(route('admin.page.edit', array('id' => $id)))
            ->with('success', lang('page_mode_visualize_clear') . lang('success'));
    }

    /**
     * 删除（DELETE route=page/{id}）
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.page'));
        }

        $result = $this->pageService->delete($id, $request->post());
        return $this->respondDeleteResult($result);
    }
}
