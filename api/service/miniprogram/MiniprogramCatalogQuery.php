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

namespace Dou\Api\Service\Miniprogram;

use Dou\Core\Facade\DB;
use Dou\Core\Facade\Url;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Pricing\PricingService;
use Dou\Core\Support\CategoryIds;
use Dou\Core\Support\Str;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序通用列表查询。
 *
 * 接受任意带有 category_id / id / price 字段的模块表，返回小程序列表 ViewModel。
 */
class MiniprogramCatalogQuery extends BaseService
{
    /** @var PricingService */
    private $pricingService;

    /**
     * @param PricingService $pricingService
     */
    public function __construct(PricingService $pricingService)
    {
        $this->pricingService = $pricingService;
    }

    /**
     * @param string $module
     * @param string|int $catId
     * @param string|int $num
     * @param string $sort SQL ORDER BY 片段（已由调用方校验）
     * @return array
     */
    public function listing($module, $catId = '', $num = '', $sort = '')
    {
        $query = DB::table($module);

        if ($catId && $catId != 'ALL') {
            $categoryIdList = CategoryIds::subtree($module . '_category', $catId);
            if ($categoryIdList) {
                $query->where('category_id', 'IN', $categoryIdList);
            }
        }

        if ($module == 'product' || $module == 'article') {
            $query->where('status', 1);
        }

        $order = $sort ? $sort . ', id DESC' : 'id DESC';
        $query->order($order);

        if ($num) {
            $query->limit($num);
        }

        $rows = $query->select();
        $list = array();
        $i = 0;
        $priceDiscuss = lang('price_discuss');
        $auth = auth('api');
        $userId = $auth ? (int) $auth->id() : 0;

        foreach ((array) $rows as $row) {
            $i++;
            $price = isset($row['price']) ? floatval($row['price']) : 0;
            $stock = isset($row['stock']) ? intval($row['stock']) : 0;
            $sales = isset($row['sales']) ? intval($row['sales']) : 0;
            $denominator = $stock + $sales;
            $createdAtTs = isset($row['created_at']) ? Util::toTimestamp($row['created_at']) : null;

            $list[] = array(
                'id' => isset($row['id']) ? $row['id'] : 0,
                'title' => isset($row['title']) ? $row['title'] : '',
                'name' => isset($row['name']) ? $row['name'] : '',
                'price' => $price > 0 ? Util::formatPrice($price) : $priceDiscuss,
                'sale_price' => $price > 0 ? $this->pricingService->salePrice($module, $row['id'], $userId) : array(),
                'click' => isset($row['click']) ? $row['click'] : 0,
                'stock' => $stock,
                'sales' => $sales,
                'sales_percentage' => $denominator > 0 ? intval(($sales / $denominator) * 100) : 0,
                'created_at' => $createdAtTs !== null ? date('Y-m-d', $createdAtTs) : '',
                'description' => !empty($row['description']) ? $row['description'] : Str::excerpt(isset($row['content']) ? $row['content'] : '', 320),
                'image' => attachment()->url(isset($row['image']) ? $row['image'] : ''),
                'thumb' => attachment()->url(isset($row['image']) ? $row['image'] : '', true),
                'url' => Url::urlMini($module, isset($row['id']) ? $row['id'] : ''),
                'bor' => $i % 2 == 0 ? ' noBor' : ''
            );
        }

        return $list;
    }
}
