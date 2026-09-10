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

namespace Dou\Api\Controller\Product;

use Dou\Api\Controller\BaseController;
use Dou\Core\Facade\RouteId;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Front\Model\Product\ProductCategory;
use Dou\Front\Service\Product\ProductService as FrontProductService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序商品详情控制器
 */
class ProductController extends BaseController
{
    /** @var FrontProductService */
    private $productService;

    /**
     * @param FrontProductService $productService
     */
    public function __construct(FrontProductService $productService)
    {
        $this->productService = $productService;
    }

    public function index(Request $request)
    {
        $categoryIdInput = $request->input('id');
        $catId = RouteId::category('product_category', $categoryIdInput, $request->input('category_slug'));
        if ($catId == -1) {
            throw new DomainException(lang('page_wrong'));
        }

        // 显式传 id=0 表示全部分类（如小程序首页全量分页）；未传 id 仍回退第一个分类
        $allCategories = ((string) $categoryIdInput === '0');

        $archive = Util::parseArchive($request->input('year'), $request->input('month'));
        if ($archive) {
            $catId = 0;
        }

        $pageSize = 6;
        $data = $this->productService->buildProductListData(
            $catId,
            $request->integer('page', 1),
            $pageSize,
            $request->integer('brand_id'),
            $request->input('by'),
            $request->input('sort'),
            $archive,
            true,
            (int) auth('api')->id(),
            $allCategories
        );

        $cateInfo = $this->buildProductCategoryInfo($catId);

        $data['title'] = (!empty($cateInfo['name'])) ? $cateInfo['name'] : lang('product');
        $data['category_id'] = $catId;
        $data['product_category'] = ProductCategory::tree($catId);

        return ApiResponse::success($data);
    }

    public function show(Request $request)
    {
        $id = RouteId::column('product', $request->input('id'), $request->input('category_slug'), $request->input('slug'));
        if ($id == -1) {
            throw new DomainException(lang('page_wrong'));
        }

        $product = $this->productService->buildProductShowData($id, (int) auth('api')->id());
        if (!$product) {
            throw new DomainException(lang('page_wrong'));
        }

        $data = array(
            'defined' => isset($product['defined']) ? $product['defined'] : array(),
            'title' => lang('product_detail'),
            'product' => $product,
            'open' => array(
                'order' => Config::get('features.order', false),
            ),
        );

        $coupon = Module::make('coupon');
        if ($coupon) {
            $data['coupon_list'] = $coupon->getCouponList((int) auth('api')->id());
        }

        return ApiResponse::success($data);
    }

    public function attributeList(Request $request)
    {
        $id = RouteId::column('product', $request->input('id'), $request->input('category_slug'), $request->input('slug'));
        if ($id == -1) {
            throw new DomainException(lang('page_wrong'));
        }

        $attributeData = json_decode(str_replace('\\', '', $request->input('attribute_data')), true);
        if (!is_array($attributeData)) {
            $attributeData = array();
        }

        $attribute = Module::make('attribute');
        if (!$attribute) {
            return ApiResponse::success(array());
        }
        $data = $this->productService->buildApiAttributeData($id, (int) auth('api')->id(), $attributeData, $attribute);
        if (empty($data)) {
            return ApiResponse::success(array());
        }
        return ApiResponse::success($data);
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
            $cateInfo['url'] = route('product.category', array('category_id' => $catId));
        }

        return $cateInfo;
    }
}
