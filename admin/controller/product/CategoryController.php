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

namespace Dou\Admin\Controller\Product;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Product\CategoryFormRequest;
use Dou\Admin\Service\Product\CategoryService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台商品分类控制器
 *
 * 格式校验由 CategoryFormRequest 完成（Action 注入按方法名绑定 scene），失败时抛出 DomainException；
 * 业务规则阻断由 Service 抛出 DomainException；
 * 两者统一由入口文件的全局处理器捕获并调用 `$context->message->respond()` 输出。
 */
class CategoryController extends BaseController
{
    /** @var CategoryService */
    private $categoryService;

    /**
     * @param CategoryService $categoryService
     */
    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'product',
            'submenu' => 'product_category',
        );
    }

    /**
     * 分类列表
     *
     * @return Response
     */
    public function index()
    {
        return $this->view('product_category.htm', [
            'ur_here' => lang('product_category'),
            'page_actions' => array(
                array('href' => route('admin.product.category.create'), 'text' => lang('product_category_create'), 'style' => 'add'),
            ),
            'rec' => 'default',
            'product_category' => $this->categoryService->flat(),
        ]);
    }

    /**
     * 添加表单
     *
     * @return Response
     */
    public function create()
    {
        return $this->view('product_category.htm', [
            'ur_here' => lang('product_category_create'),
            'page_actions' => array(
                array('href' => route('admin.product.category'), 'text' => lang('product_category'), 'style' => ''),
            ),
            'rec' => 'create',
            'product_category' => $this->categoryService->flat(),
            'cat_info' => $this->categoryService->buildCategoryDefaultData(),
            'btn_lang' => language()->buildLangButtons('product_category', '', 'name, keywords, description'),
        ]);
    }

    /**
     * 提交新增
     *
     * 字段白名单与校验规则定义在 CategoryFormRequest::rules()。
     * Action 注入会按方法名自动绑定 scene=store。
     *
     * @param CategoryFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(CategoryFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $newCatId = $this->categoryService->insert($data, (int) auth('admin')->id());

        return redirect(route('admin.product.category.edit', array('id' => $newCatId)))
            ->with('success', lang('product_category_add_succes'), route('admin.product.category'), lang('back_to_list'));
    }

    /**
     * 编辑表单
     *
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $catId = $request->integer('id', 0);
        if ($catId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.product.category'));
        }

        $catInfo = $this->categoryService->buildCategoryEditData($catId);
        if ($catInfo === null) {
            throw new DomainException(lang('illegal'), route('admin.product.category'));
        }

        return $this->view('product_category.htm', [
            'ur_here' => lang('product_category_edit'),
            'page_actions' => array(
                array('href' => route('admin.product.category'), 'text' => lang('product_category'), 'style' => ''),
            ),
            'rec' => 'edit',
            'cat_info' => $catInfo,
            'product_category' => $this->categoryService->flat($catId),
            'btn_lang' => language()->buildLangButtons('product_category', $catId, 'name, keywords, description'),
        ]);
    }

    /**
     * 提交更新
     *
     * 字段白名单与校验规则定义在 CategoryFormRequest::rules()。
     * Action 注入会按方法名自动绑定 scene=update。
     *
     * @param CategoryFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(CategoryFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $this->categoryService->update($data, (int) auth('admin')->id());

        return redirect(route('admin.product.category.edit', array('id' => (int) $data['category_id'])))
            ->with('success', lang('product_category_edit_succes'), route('admin.product.category'), lang('back_to_list'));
    }

    /**
     * 删除
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $catId = $request->integer('id', 0);
        if ($catId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.product.category'));
        }

        $result = $this->categoryService->delete($catId, $request->post());
        return $this->respondDeleteResult($result);
    }
}
