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

namespace Dou\Admin\Controller\Article;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Model\Article\ArticleCategory;
use Dou\Admin\Request\Article\ArticleFormRequest;
use Dou\Admin\Service\Article\ArticleService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台文章控制器
 *
 * 格式校验由 ArticleFormRequest 完成（Action 注入按方法名绑定 scene），失败时抛出 DomainException；
 * 业务规则由 Service 内处理；统一由入口捕获 DomainException 并由 `$context->message->respond()` 输出。
 */
class ArticleController extends BaseController
{
    /** @var ArticleService */
    private $articleService;

    /**
     * @param ArticleService $articleService
     */
    public function __construct(ArticleService $articleService)
    {
        $this->articleService = $articleService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'article',
        );
    }

    /**
     * 文章列表
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $catId = $request->integer('category_id', 0);
        $keyword = trim((string) $request->input('keyword', ''));
        $page = $request->integer('page', 1);

        // 获取带分页的列表
        $result = $this->articleService->buildArticleListData($catId, $keyword, $page);

        return $this->view('article.htm', [
            'ur_here' => lang('article_list'),
            'page_actions' => array(
                array('href' => route('admin.article.create'), 'text' => lang('article_create'), 'style' => 'add'),
            ),
            'rec' => 'default',
            'category_id' => $catId,
            'keyword' => $keyword,
            'article_category' => ArticleCategory::flat(),
            'article_list' => $result['list'],
            'pager' => $result['pager'],
        ]);
    }

    /**
     * 添加表单。
     *
     * 进入本 action 时顺手懒清当前 admin 在 article 模块下 7 天前未认领的草稿附件。
     *
     * @return Response
     */
    public function create()
    {
        $adminId = (int) auth('admin')->id();
        attachment()->cleanupUserDrafts('admin', $adminId, 'article');
        $draftToken = attachment()->newDraftToken('admin', $adminId);

        $article = $this->articleService->buildArticleDefaultData();

        return $this->view('article.htm', [
            'ur_here' => lang('article_create'),
            'page_actions' => array(
                array('href' => route('admin.article'), 'text' => lang('article_list'), 'style' => ''),
            ),
            'rec' => 'create',
            'article_category' => ArticleCategory::flat(),
            'item_id' => 0,
            'draft_token' => $draftToken,
            'item_content' => '',
            'article' => $article,
            'btn_lang' => language()->buildLangButtons('article', '', 'title, content, keywords, description, defined'),
        ]);
    }

    /**
     * 提交新增
     *
     * 字段白名单与校验规则定义在 ArticleFormRequest::rules()。
     * Action 注入会按方法名自动绑定 scene=store。draft_token 由 store scene 强制必填。
     *
     * @param ArticleFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(ArticleFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $draftToken = (string) $request->post('draft_token', '');
        $adminId = (int) auth('admin')->id();
        $newId = $this->articleService->insert($data, $draftToken, $adminId);

        return redirect(route('admin.article.edit', array('id' => $newId)))
            ->with('success', lang('article_add_succes'), route('admin.article'), lang('back_to_list'));
    }

    /**
     * 编辑表单
     *
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.article'));
        }

        $article = $this->articleService->buildArticleEditData($id);
        if ($article === null) {
            throw new DomainException(lang('illegal'), route('admin.article'));
        }

        return $this->view('article.htm', [
            'ur_here' => lang('article_edit'),
            'page_actions' => array(
                array('href' => route('admin.article'), 'text' => lang('article_list'), 'style' => ''),
            ),
            'rec' => 'edit',
            'article_category' => ArticleCategory::flat(),
            'item_id' => $id,
            'draft_token' => '',
            'item_content' => $article['item_content'],
            'article' => $article,
            'btn_lang' => language()->buildLangButtons('article', $id, 'title, content, keywords, description, defined'),
        ]);
    }

    /**
     * 提交更新（仅接受 POST；直接 GET 本地址会因无 token 触发非法操作并清空会话）
     *
     * 字段白名单与校验规则定义在 ArticleFormRequest::rules()。
     * Action 注入会按方法名自动绑定 scene=update。
     *
     * @param ArticleFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(ArticleFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $this->articleService->update($data, (int) auth('admin')->id());
        return redirect(route('admin.article.edit', array('id' => (int) $data['id'])))
            ->with('success', lang('article_edit_succes'), route('admin.article'), lang('back_to_list'));
    }

    /**
     * 删除
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.article'));
        }

        $result = $this->articleService->delete($id, $request->post());
        return $this->respondDeleteResult($result);
    }

    /**
     * 批量操作
     *
     * @param Request $request
     * @return Response
     */
    public function action(Request $request)
    {
        $result = $this->articleService->action($request->post());

        return redirect($result['back_url'])->with('success', $result['message']);
    }
}
