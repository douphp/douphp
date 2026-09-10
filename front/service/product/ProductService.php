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

namespace Dou\Front\Service\Product;

use Dou\Core\Facade\DB;
use Dou\Core\Facade\Url;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Service\Attribute\AttributeService;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Content\MarkdownRenderer;
use Dou\Core\Service\Pricing\PricingService;
use Dou\Core\Support\Str;
use Dou\Core\Support\Util;
use Dou\Front\Model\Brand\Brand;
use Dou\Front\Model\Product\Product as FrontProductModel;
use Dou\Front\Service\Sort\ListSortOptionBuilder;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台商品业务层（分类列表 / 详情）
 */
class ProductService extends BaseService
{
    /** @var PricingService */
    private $pricingService;

    /** @var MarkdownRenderer */
    private $markdown;

    /** @var ListSortOptionBuilder */
    private $sortBuilder;

    /**
     * @param PricingService $pricingService
     * @param MarkdownRenderer $markdown
     * @param ListSortOptionBuilder $sortBuilder
     */
    public function __construct(PricingService $pricingService, MarkdownRenderer $markdown, ListSortOptionBuilder $sortBuilder)
    {
        $this->pricingService = $pricingService;
        $this->markdown = $markdown;
        $this->sortBuilder = $sortBuilder;
    }

    /**
     * 构建分类列表页数据（含品牌筛选、排序；归档区间由 $archive 传入）。
     *
     * @param int $catId 分类 id（0 表示全部）
     * @param int $page 当前页
     * @param int $pageSize 分页大小（由 Controller 决定）
     * @param int $brandId 品牌 id（0 表示不按品牌筛）
     * @param string $sortBy 排序字段 by
     * @param string $sortDir 排序方向 sort
     * @param array $archive 空数组表示非归档；否则须含 start/end/year/month（见 Util::parseArchive）
     * @param bool $isApi 是否 API 场景
     * @param int $userId 当前会员 id（0 表示未登录；shell 层显式传入，避免服务内反查 guard）
     * @param bool $allCategories true 时不回退第一个分类，catId 0/-1 视为全部分类
     * @return array product_list、pager、sort_list、brand
     */
    public function buildProductListData($catId, $page, $pageSize, $brandId, $sortBy, $sortDir, $archive = array(), $isApi = false, $userId = 0, $allCategories = false)
    {
        $pageSize = (int) $pageSize;
        if ($pageSize < 1) {
            $pageSize = 10;
        }

        $brandId = (int) $brandId;
        if (!$allCategories && ($catId == -1 || $catId == 0) && $isApi) {
            $catId = FrontProductModel::findFirstCategoryId();
        }

        if ($archive) {
            $pageUrl = route('product.category', ['category_id' => 0]) . '/' . $archive['year'];
            if (!empty($archive['month'])) {
                $pageUrl .= '/' . $archive['month'];
            }
            if ($brandId) {
                $pageUrl .= '?brand_id=' . $brandId;
            }
        } else {
            $pageUrl = route('product.category', ['category_id' => $catId], $brandId ? ['query' => ['brand_id' => $brandId]] : []);
        }

        $douSort = $this->sortBuilder->buildSortOptions(
            'sales, price, created_at, sort',
            'price',
            'sort ASC, id DESC',
            $sortBy,
            $sortDir,
            $pageUrl
        );

        // AR：with('category') 批量取分类，casts/prefetchers/translatable 承接附件URL/缩略图/日期/多语言
        // 列表页归档与分类互斥：归档存在时跳过分类 scope。
        $query = FrontProductModel::with('category')
            ->published();
        if ($archive) {
            $query->filterByArchive($archive);
        } else {
            $query->filterByCategory($catId);
        }
        $result = $query
            ->filterByBrand($brandId)
            ->order($douSort['sql'])
            ->paginate((int) $pageSize, (int) $page, $pageUrl);

        $ids = array();
        foreach ($result['list'] as $model) {
            $ids[] = (int) $model->getKey();
        }

        $userId = (int) $userId;
        $favoritesMap = array();
        if (Config::get('features.favorites', false) && Config::get('features.user', false) && $userId > 0 && !empty($ids)) {
            $favSvc = Module::make('favorites');
            if ($favSvc !== null && method_exists($favSvc, 'mapFavoritedIds')) {
                $favoritesMap = $favSvc->mapFavoritedIds('product', $ids);
            }
        }
        $imageOtherMap = !empty($ids) ? attachment()->galleryFirstMap('product', $ids) : array();

        $productList = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $rawPrice = (float) $model->getRawAttribute('price');
            $catName = isset($row['category']['name']) ? $row['category']['name'] : '';

            $url = $isApi ? Url::urlMini('product', $row['id']) : route('product.show', array('id' => $row['id']));
            $description = Str::excerpt($row['description'], 150, false);

            if (isset($favoritesMap[$row['id']])) {
                $favorites = array('class' => ' ed', 'text' => lang('favorites_ed'));
            } elseif (!empty(Config::get('features.favorites', false)) && $userId > 0) {
                $favorites = array('class' => '', 'text' => lang('favorites_btn'));
            } else {
                $favorites = null;
            }

            $emptySale = array();
            $productList[] = array(
                'id' => $row['id'],
                'category_id' => $row['category_id'],
                'title' => $row['title'],
                'defined' => $row['defined'],
                'price' => $rawPrice > 0 ? Util::formatPrice($rawPrice) : lang('price_discuss'),
                'sale_price' => $rawPrice > 0 ? $this->pricingService->salePrice('product', $row['id'], $userId, $model->getAttributes()) : $emptySale,
                'thumb' => $row['thumb'],
                'image' => $row['image'],
                'image_other' => isset($imageOtherMap[$row['id']]) ? $imageOtherMap[$row['id']] : '',
                'created_at' => $row['created_at'],
                'description' => $description,
                'favorites' => $favorites,
                'url' => $url,
                'cate_info' => array(
                    'category_id' => $row['category_id'],
                    'name' => $catName,
                    'url' => $isApi ? Url::urlMini('product_category', $row['category_id']) : route('product.category', array('category_id' => $row['category_id'])),
                ),
            );

            if ($isApi) {
                $total = $row['stock'] + $row['sales'];
                $productList[count($productList) - 1]['stock'] = $row['stock'];
                $productList[count($productList) - 1]['sales'] = $row['sales'];
                $productList[count($productList) - 1]['sales_percentage'] = $total > 0 ? intval(($row['sales'] / $total) * 100) : 0;
            }
        }

        $brandRow = null;
        if ($brandId && Config::get('features.brand', false)) {
            $brandModel = Brand::find($brandId);
            if ($brandModel) {
                $brandRow = $brandModel->toArray();
            }
        }

        $data = array(
            'product_list' => $productList,
            'pager' => $result['pager'],
            'sort_list' => $douSort['field'],
            'brand' => $brandRow,
        );

        return $data;
    }

    /**
     * 详情页：仅加工商品主体
     *
     * @param int $productId 已通过 RouteIdValidator::column 校验
     * @param int|string|null $userId 会员 id；shell 层显式传入（API 端取 auth('api')->id()，前台取 auth('front')->id()），未登录传 0
     * @return array|null 商品行，无效时 null
     */
    public function buildProductShowData($productId, $userId = null)
    {
        $productId = (int) $productId;
        if ($productId < 1) {
            return null;
        }

        $model = FrontProductModel::findPublishedById($productId);
        if (!$model) {
            return null;
        }

        // AR：casts(image/created_at/defined)、translatable(title/content/description) 已在 toArray 承接；
        // 这里转成数组形态，避免后续 langBox（NullLanguageService 等实现）按 is_array 判断而把 Model 整体清空。
        $product = $model->toArray();

        $uid = ($userId !== null && $userId !== '') ? $userId : '';

        if (defined('IS_API') && IS_API) {
            $product['price_format'] = $product['price'] > 0 ? Util::formatPrice($product['price']) : lang('price_discuss');
        } else {
            $product['price'] = $product['price'] > 0 ? Util::formatPrice($product['price']) : lang('price_discuss');
        }

        $product['sale_price'] = $this->pricingService->salePrice('product', $productId, $uid);
        $product['gallery_list'] = attachment()->gallery('product', $productId, 'gallery', true);
        $favorites = Module::make('favorites');
        $product['favorites'] = $favorites ? $favorites->getFavoritesState('product', $productId, $uid) : null;
        $product['brand'] = array();
        if (Config::get('features.brand', false)) {
            $brandModel = Brand::find($product['brand_id']);
            if ($brandModel) {
                $product['brand'] = $brandModel->toArray();
            }
        }

        if (!(defined('IS_API') && IS_API)) {
            $product['image_other'] = attachment()->galleryFirst('product', $productId);
            $product['model_list'] = $this->buildModelList($product['model'], $productId);
            $product = language()->langBox($product, 'product', 'title, content, defined, keywords, description');
            // langBox 在多语言翻译命中时会把 cast 后的 pairs 数组覆写为翻译后的 raw 字符串，
            // 这里走 parseDefinedPairs 重归一化：未翻译时（数组）幂等返回，翻译命中时再解析。
            $product['defined'] = Util::parseDefinedPairs(isset($product['defined']) ? $product['defined'] : '');
        }

        $product['content'] = $this->markdown->toHtml($product['content']);

        return $product;
    }

    /**
     * 详情页型号列表数据（同 model 字段的其它商品）。
     *
     * @param string $model 商品型号字符串
     * @param int|string $currentId 当前商品 id
     * @return array
     */
    public function buildModelList($model, $currentId)
    {
        if (!$model) {
            return [];
        }

        $rows = DB::table('product')
            ->field('id, title, image')
            ->where('model', $model)
            ->order('sort ASC, id DESC')
            ->select();

        $modelList = [];
        foreach ((array) $rows as $row) {
            $modelList[] = array(
                'title' => $row['title'],
                'thumb' => attachment()->url($row['image'], true),
                'image' => attachment()->url($row['image']),
                'cur' => $row['id'] == $currentId,
                'url' => route('product.show', ['id' => $row['id']]),
            );
        }

        return $modelList;
    }

    /**
     * @param int $catId
     * @return array|false
     */
    public function findCategoryById($catId)
    {
        return DB::table('product_category')->where('id', (int) $catId)->find();
    }

    /**
     * API 商品属性选中后价格数据
     *
     * @param int $productId
     * @param int|null $userId
     * @param array $attributeData
     * @param AttributeService $attribute
     * @return array
     */
    public function buildApiAttributeData($productId, $userId, array $attributeData, AttributeService $attribute)
    {
        $product = FrontProductModel::findApiAttributeBase($productId);
        if (!$product) {
            return array();
        }

        $salePrice = $this->pricingService->salePrice('product', $productId, $userId);
        $moneyExchangePoint = $product['price'] > 0 ? $product['point'] / $product['price'] : 0;
        $attributeList = array();
        if (!empty(Config::get('features.attribute', false))) {
            $attributeList = $attribute->getAttributeList('product', $product['category_id'], $product['id'], 'common', $attributeData);
            foreach ($attributeList as $attribute) {
                $product['price'] = $product['price'] + $attribute['selected_value_price_change'];
                if ($salePrice) {
                    $salePrice['value'] = $salePrice['value'] + $attribute['selected_value_price_change'];
                }
                $product['point'] = $product['point'] + $attribute['selected_value_price_change'] * $moneyExchangePoint;
            }
        }

        if ($salePrice) {
            $salePrice['format'] = Util::formatPrice($salePrice['value']);
        }

        return array(
            'attribute_list' => $attributeList ? $attributeList : array(),
            'box' => array(
                'price' => Util::formatPrice($product['price']),
                'sale_price' => $salePrice,
                'point' => ceil($product['point']),
            ),
        );
    }
}
