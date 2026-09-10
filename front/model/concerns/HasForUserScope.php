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

namespace Dou\Front\Model\Concerns;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Orm\Builder;
use Dou\Core\Service\Pricing\PricingService;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员视图 scope：列表语境下按当前会员补齐 price / sale_price / favorites。
 *
 * 在 Builder 水合后整批补值（一次 favorites 批量查询 + 单次 price 格式化）。
 * 详情读路径不挂 forUser，详情 toArray 保留原始 price、不含 sale_price。
 * $userId 由 shell 层显式传入，trait 内部不读 auth / request。
 */
trait HasForUserScope
{
    /**
     * 列表会员视图：补 price（格式化）/ sale_price（会员价）/ favorites（收藏态）。
     *
     * @param Builder $query
     * @param int $userId 当前会员 ID（0 = 未登录）
     * @return Builder
     */
    public function scopeForUser(Builder $query, $userId)
    {
        $userId = (int) $userId;
        $module = $this->getTable();

        $query->pushAfterHydrate(function ($collection) use ($userId, $module) {
            $favoritesOn = (bool) Config::get('features.favorites', false);

            $favoritesMap = array();
            if ($favoritesOn && $userId > 0) {
                $ids = array();
                foreach ($collection as $model) {
                    $ids[] = $model->getKey();
                }
                $favoriteSvc = Module::make('favorites');
                if (!empty($ids) && $favoriteSvc !== null && method_exists($favoriteSvc, 'mapFavoritedIds')) {
                    $favoritesMap = $favoriteSvc->mapFavoritedIds($module, $ids, $userId);
                }
            }

            $pricing = app(PricingService::class);

            foreach ($collection as $model) {
                $id = $model->getKey();

                if (isset($favoritesMap[$id])) {
                    $model->setAttribute('favorites', array('class' => ' ed', 'text' => lang('favorites_ed')));
                } elseif ($favoritesOn && $userId > 0) {
                    $model->setAttribute('favorites', array('text' => lang('favorites_btn')));
                }

                $attributes = $model->getAttributes();
                if (array_key_exists('price', $attributes)) {
                    $rawPrice = $attributes['price'];
                    $salePrice = $rawPrice > 0 ? $pricing->salePrice($module, $id, $userId, $attributes) : array();
                    $model->setAttribute('price', $rawPrice > 0 ? Util::formatPrice($rawPrice) : lang('price_discuss'));
                    $model->setAttribute('sale_price', $salePrice);
                }
            }
        });

        return $query;
    }
}
