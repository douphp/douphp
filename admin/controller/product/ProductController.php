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
use Dou\Admin\Model\Product\ProductCategory;
use Dou\Admin\Request\Product\ProductFormRequest;
use Dou\Admin\Service\Product\ProductService;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Service\Content\MarkdownRenderer;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Model\Brand\Brand;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台商品控制器（route=product/...）
 *
 * 格式校验由 ProductFormRequest 完成（Action 注入按方法名绑定 scene），失败时抛出 DomainException；
 * 业务阻断由 Service 抛出 DomainException；成功反馈走 `redirect($url)->with('success', $msg)` + flash 渲染，
 * 删除二次确认走基类 `respondDeleteResult()` 分流。
 */
class ProductController extends BaseController
{
    /** @var ProductService */
    private $productService;

    /** @var MarkdownRenderer */
    private $markdown;

    /**
     * @param ProductService $productService
     * @param MarkdownRenderer $markdown
     */
    public function __construct(
        ProductService $productService,
        MarkdownRenderer $markdown
    ) {
        $this->productService = $productService;
        $this->markdown = $markdown;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'product',
        );
    }

    /**
     * 商品列表
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $catId = $request->integer('category_id', 0);
        $keyword = trim((string) $request->input('keyword', ''));
        $page = $request->integer('page', 1);

        $result = $this->productService->buildProductListData($catId, $keyword, $page);

        $userLevelOption = Module::make('user.user_level_option_builder');
        return $this->view('product.htm', [
            'ur_here' => lang('product_list'),
            'page_actions' => array(
                array('href' => route('admin.product.create'), 'text' => lang('product_create'), 'style' => 'add'),
            ),
            'rec' => 'default',
            'category_id' => $catId,
            'keyword' => $keyword,
            'product_category' => ProductCategory::flat(),
            'product_list' => $result['list'],
            'pager' => $result['pager'],
            'user_level_option' => $userLevelOption !== null ? $userLevelOption->options() : array(),
        ]);
    }

    /**
     * 添加表单
     *
     * @return Response
     */
    public function create()
    {
        $adminId = (int) auth('admin')->id();
        attachment()->cleanupUserDrafts('admin', $adminId, 'product');
        $draftToken = attachment()->newDraftToken('admin', $adminId);

        $product = $this->productService->buildProductDefaultData(0);

        $userLevelOption = Module::make('user.user_level_option_builder');

        $dataList = data()->query('product', '0');

        return $this->view('product.htm', [
            'ur_here' => lang('product_create'),
            'page_actions' => array(
                array('href' => route('admin.product'), 'text' => lang('product_list'), 'style' => ''),
            ),
            'rec' => 'create',
            'item_id' => 0,
            'draft_token' => $draftToken,
            'item_content' => '',
            'product' => $product,
            'product_category' => ProductCategory::flat(),
            'brand_list' => Config::get('features.brand', false) ? Brand::applyDefaultOrder()->get() : array(),
            'user_level_option' => $userLevelOption !== null ? $userLevelOption->options() : array(),
            'btn_lang' => language()->buildLangButtons('product', '', 'title, content, defined, keywords, description'),
            'data_list' => $dataList,
        ]);
    }

    /**
     * 提交新增
     *
     * 字段白名单与校验规则定义在 ProductFormRequest::rules()。
     * Action 注入会按方法名自动绑定 scene=store。
     *
     * @param ProductFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(ProductFormRequest $formRequest, Request $request)
    {
        $draftToken = (string) $request->post('draft_token', '');
        $adminId = (int) auth('admin')->id();
        $newId = $this->productService->insert($formRequest->validated(), $draftToken, $adminId);
        return redirect(route('admin.product.edit', array('id' => $newId)))
            ->with('success', lang('product_add_succes'), route('admin.product'), lang('back_to_list'));
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
            throw new DomainException(lang('illegal'), route('admin.product'));
        }

        $product = $this->productService->buildProductEditData($id);
        if ($product === null) {
            throw new DomainException(lang('item_not_exist'), route('admin.product'));
        }

        $attribute = Module::make('attribute');
        $attributeList = $attribute
            ? $attribute->getAttributeList('product', $product['category_id'], $product['id'], 'html')
            : '';

        $dataList = data()->query('product', (string) $id);

        $userLevelOption = Module::make('user.user_level_option_builder');

        return $this->view('product.htm', [
            'ur_here' => lang('product_edit'),
            'page_actions' => array(
                array('href' => route('admin.product'), 'text' => lang('product_list'), 'style' => ''),
            ),
            'rec' => 'edit',
            'item_id' => $id,
            'draft_token' => '',
            'item_content' => $this->markdown->toHtml($product['content']),
            'product' => $product,
            'product_category' => ProductCategory::flat(),
            'brand_list' => Config::get('features.brand', false) ? Brand::applyDefaultOrder()->get() : array(),
            'user_level_option' => $userLevelOption !== null ? $userLevelOption->options(null, unserialize($product['level_price'])) : array(),
            'btn_lang' => language()->buildLangButtons('product', $id, 'title, content, defined, keywords, description'),
            'attribute_list' => $attributeList,
            'data_list' => $dataList,
        ]);
    }

    /**
     * 提交更新
     *
     * 字段白名单与校验规则定义在 ProductFormRequest::rules()。
     * Action 注入会按方法名自动绑定 scene=update。
     *
     * @param ProductFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(ProductFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $this->productService->update($data, (int) auth('admin')->id());
        return redirect(route('admin.product.edit', array('id' => (int) $data['id'])))
            ->with('success', lang('product_edit_succes'), route('admin.product'), lang('back_to_list'));
    }

    /**
     * 缩略图批量处理
     *
     * @param Request $request
     * @return Response
     */
    public function thumb(Request $request)
    {
        $bundle = $this->productService->buildThumbData($request->post());

        if ($request->input('confirm')) {
            $this->productService->thumbFlush($bundle['query'], $bundle['mask_tag']);
        }

        return $this->view('product.htm', [
            'ur_here' => lang('product_thumb'),
            'page_actions' => array(
                array('href' => route('admin.product'), 'text' => lang('product_list'), 'style' => ''),
            ),
            'rec' => 'thumb',
            'mask' => $bundle['mask'],
        ]);
    }

    /**
     * 型号关联 Ajax（参数非法时直接返回空片段，由前端 HTML 替换语义自处理）
     *
     * @param Request $request
     * @return Response
     */
    public function model(Request $request)
    {
        $mode = $request->input('mode');
        $id = $request->input('id');
        $actionId = $request->input('action_id');
        if (!Check::letter($mode) || !Check::number($id) || !Check::number($actionId)) {
            return $this->response('');
        }

        return $this->response($this->productService->model($mode, $id, $actionId));
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
            throw new DomainException(lang('illegal'), route('admin.product'));
        }

        $result = $this->productService->delete($id, $request->post());
        return $this->respondDeleteResult($result);
    }

    /**
     * 列表批量操作
     *
     * @param Request $request
     * @return Response
     */
    public function action(Request $request)
    {
        $result = $this->productService->action($request->post());

        return redirect($result['back_url'])->with('success', $result['message']);
    }
}
