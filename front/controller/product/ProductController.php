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

namespace Dou\Front\Controller\Product;

use Dou\Core\Facade\RouteId;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Model\Product\Product;
use Dou\Front\Model\Product\ProductCategory;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Product\ProductService;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SchemaService;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台商品控制器
 *
 * 列表：归档用 Util::parseArchive()，cate_info 由控制器直接组装；
 * 详情：buildProductShowData($id, $userId) 仅返回商品主体；shell 端须显式把 auth->id() 传入，
 *       内部据此判断收藏状态（features.favorites + 已登录）。
 */
class ProductController extends BaseController
{
    /** @var ProductService */
    private $productService;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /** @var SeoResolver */
    private $seo;

    /** @var SchemaService */
    private $schema;

    /**
     * @param ProductService $productService
     * @param NavigationBuilder $nav
     * @param BreadcrumbBuilder $breadcrumb
     * @param SeoResolver $seo
     * @param SchemaService $schema
     */
    public function __construct(
        ProductService $productService,
        NavigationBuilder $nav,
        BreadcrumbBuilder $breadcrumb,
        SeoResolver $seo,
        SchemaService $schema
    ) {
        $this->productService = $productService;
        $this->nav = $nav;
        $this->breadcrumb = $breadcrumb;
        $this->seo = $seo;
        $this->schema = $schema;
    }

    /**
     * 产品分类列表
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $catId = RouteId::category(
            'product_category',
            $request->route('id'),
            $request->route('category_slug'),
            $request->route('year'),
            $request->route('month')
        );
        if ($catId == -1) {
            throw new DomainException('page_wrong', HOME_URL);
        }

        $archive = Util::parseArchive($request->route('year'), $request->route('month'));
        if ($archive) {
            $catId = 0;
        }

        $pageSize = (int) Config::get('pagination.product', 10);
        $pageSize = $pageSize ? $pageSize : 10;
        $result = $this->productService->buildProductListData(
            $catId,
            $request->route('page', 1),
            $pageSize,
            $request->integer('brand_id'),
            $request->input('by'),
            $request->input('sort'),
            $archive,
            false,
            (int) auth('front')->id()
        );

        $cateInfo = $this->buildProductCategoryInfo($catId);
        if (!is_array($cateInfo)) {
            $cateInfo = array(
                'name' => '',
                'keywords' => '',
                'description' => '',
                'parent_id' => 0,
            );
        }
        if ($archive) {
            $cateInfo['name'] = Util::archiveLabel($archive);
        }

        $urHere = $this->breadcrumb->build('product_category', $catId, '', $archive);

        return $this->view('product_category.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('product_category', $catId, '', $archive),
            'keywords' => $this->seo->keywords($cateInfo['keywords'], $archive),
            'description' => $this->seo->description($cateInfo['description'], $archive, 'product'),

            // 导航栏
            'nav_middle_list' => $this->nav->middle(0, 'product_category', $catId, $cateInfo['parent_id']),

            // 页面骨架
            'rec' => 'index',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'cate_info' => $cateInfo,
            'sort_list' => $result['sort_list'],
            'brand' => isset($result['brand']) ? $result['brand'] : null,
            'product_category' => ProductCategory::tree($catId),
            'product_list' => $result['product_list'],
            'pager' => $result['pager'],
            'related_product' => Product::related($catId, 4, (int) auth('front')->id()),
            'column_name' => $cateInfo['name'] ? $cateInfo['name'] : lang('product_category'),
        ]);
    }

    /**
     * 产品详情
     *
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $id = RouteId::column(
            'product',
            $request->route('id'),
            $request->route('category_slug'),
            $request->route('slug'),
            $request->route('year'),
            $request->route('month')
        );
        if ($id == -1) {
            throw new DomainException('page_wrong', HOME_URL);
        }

        $product = $this->productService->buildProductShowData($id, (int) auth('front')->id());
        if (!$product) {
            throw new DomainException('page_wrong', HOME_URL);
        }

        $catId = (int) $product['category_id'];
        $cateInfo = $this->buildProductCategoryInfo($catId);
        if (!is_array($cateInfo)) {
            $cateInfo = array(
                'name' => '',
                'keywords' => '',
                'description' => '',
                'parent_id' => 0,
            );
        }
        $parentId = $cateInfo['parent_id'];

        $attribute = Module::make('attribute');
        if ($attribute) {
            $product['attribute_list'] = $attribute->getAttributeList('product', $product['category_id'], $product['id']);
        }

        $coupon = Module::make('coupon');

        $dataList = data()->query('product', $id);

        $comment = Module::make('comment');
        $commentData = null;
        if ($comment) {
            $page = $request->route('page', 1);
            $commentData = $comment->data('product', $id, 10, $page);
        }

        $schema = $this->schema->product($product);
        $codeHead = $schema ? $schema . Config::get('site.code_head', '') : Config::get('site.code_head', '');

        $archive = Util::parseArchive($request->route('year'), $request->route('month'));
        $urHere = $this->breadcrumb->build('product_category', $catId, $product['title'], $archive);

        $viewData = array(
            // 页面头信息
            'page_title' => $this->seo->pageTitle('product_category', $catId, $product['title']),
            'keywords' => $this->seo->keywords($product['keywords']),
            'description' => $this->seo->description($product['description']),

            // 导航栏
            'nav_middle_list' => $this->nav->middle(0, 'product_category', $catId, $parentId),

            // 页面骨架
            'rec' => 'detail',
            'code_head' => $codeHead,
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'coupon_list' => $coupon ? $coupon->getCouponList((int) auth('front')->id()) : array(),
            'product_category' => ProductCategory::tree($catId),
            'product' => $product,
            'related_product' => Product::related($catId, 4, (int) auth('front')->id()),
            'lift' => Product::lift($id, $catId),
            'defined' => isset($product['defined']) ? $product['defined'] : array(),
            'column_name' => $cateInfo['name'] ? $cateInfo['name'] : lang('product_category'),
        );
        if ($dataList !== null) {
            $viewData['data_list'] = $dataList;
        }
        if ($commentData !== null) {
            $viewData['comment'] = $commentData;
        }

        return $this->view('product.dwt', $viewData);
    }

    /**
     * @param int $catId
     * @return array|false
     */
    private function buildProductCategoryInfo($catId)
    {
        $cateInfo = $this->productService->findCategoryById($catId);
        if (is_array($cateInfo)) {
            $cateInfo = language()->langBox($cateInfo, 'product_category', 'name, keywords, description');
            $cateInfo['category_id'] = (int) $catId;
            $cateInfo['url'] = route('product.category', array('category_id' => $catId));
        }

        return $cateInfo;
    }

    protected function layoutVars()
    {
        return array(
            'nav_top_list' => $this->nav->top(),
            'nav_bottom_list' => $this->nav->bottom(),
        );
    }
}
