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

namespace Dou\Core\Service\Pricing;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 定价服务（促销价 / 会员价 / 原价）。
 *
 * 「auth 只属于 shell 层」原则下：本类**不**调 auth()，shell 必须显式传入
 * `$userId`；user_level 由本类内部按 $userId 查询并以请求级缓存复用，避免
 * 在调用面散布等级数据传递逻辑。
 */
class PricingService extends BaseService
{
    /**
     * 请求级 user_id → user_level 行缓存（id=0 → null）。
     *
     * @var array<int, array|null>
     */
    private $userLevelCache = array();

    /**
     * 处理等级价数组并序列化。
     *
     * @param mixed $levelPricePost
     * @return string
     */
    public function levelPrice($levelPricePost)
    {
        if (isset($levelPricePost) && !empty($levelPricePost)) {
            foreach ($levelPricePost as $value) {
                if (empty($value)) {
                    return '';
                }
            }
        } else {
            return '';
        }

        return serialize($levelPricePost);
    }

    /**
     * 计算销售价格结构。
     *
     * shell 通过 `auth($shell)->id()` 取得用户 ID 后显式传入；user_level 由本类
     * 内部按 $userId 查询并按请求级缓存复用。
     *
     * @param string $module
     * @param int $itemId
     * @param int $userId 未登录或不需会员价时传 0
     * @param array|null $itemData 已含 price/level_price/promote_price 字段的预取行，省去再查
     * @return array
     */
    public function salePrice($module, $itemId, $userId = 0, $itemData = null)
    {
        $excludeModule = array('vip_package', 'money_package', 'chat_package');

        if ($itemData !== null && isset($itemData['price']) && isset($itemData['level_price']) && isset($itemData['promote_price'])) {
            $item = $itemData;
        } else {
            $fields = array('price');
            $hasLevelPrice = DB::fieldExist($module, 'level_price');
            $hasPromotePrice = DB::fieldExist($module, 'promote_price');

            if ($hasLevelPrice) {
                $fields[] = 'level_price';
            }
            if ($hasPromotePrice) {
                $fields[] = 'promote_price';
            }

            $item = DB::table($module)
                ->field(implode(', ', $fields))
                ->where('id', intval($itemId))
                ->find();

            if (!is_array($item)) {
                $item = array();
            }
            if (!isset($item['price'])) {
                $item['price'] = 0;
            }
            if (!isset($item['level_price'])) {
                $item['level_price'] = '';
            }
            if (!isset($item['promote_price'])) {
                $item['promote_price'] = 0;
            }
        }

        $salePrice = array(
            'value' => $item['price'],
            'format' => Util::formatPrice($item['price']),
            'type' => 'original',
            'name' => '',
        );

        if (in_array($module, $excludeModule)) {
            return $salePrice;
        }

        if ($item['promote_price'] > 0) {
            return array(
                'value' => $item['promote_price'],
                'format' => Util::formatPrice($item['promote_price']),
                'type' => 'promote',
                'name' => lang('price_promote'),
            );
        }

        $userId = (int) $userId;
        if ($userId <= 0 || !Config::get('features.user', false) || !DB::tableExist('user_level')) {
            return $salePrice;
        }

        $userLevel = $this->resolveUserLevel($userId);
        if (!is_array($userLevel) || empty($userLevel['id'])) {
            return $salePrice;
        }

        $levelId = $userLevel['id'];
        if ($item['level_price']) {
            $levelPriceMap = unserialize($item['level_price']);
            if (is_array($levelPriceMap) && isset($levelPriceMap[$levelId])) {
                return array(
                    'value' => $levelPriceMap[$levelId],
                    'format' => Util::formatPrice($levelPriceMap[$levelId]),
                    'type' => 'level',
                    'name' => lang('price_level'),
                );
            }
        }

        $discounted = number_format(
            $item['price'] * ($userLevel['good_discount'] / 100),
            Config::get('site.price_decimal', 0),
            '.',
            ''
        );
        return array(
            'value' => $discounted,
            'format' => Util::formatPrice($discounted),
            'type' => 'level',
            'name' => lang('price_level'),
        );
    }

    /**
     * 请求级缓存：按 $userId 查询会员等级行（level_id + good_discount）。
     *
     * @param int $userId
     * @return array|null
     */
    private function resolveUserLevel($userId)
    {
        $userId = (int) $userId;
        if (array_key_exists($userId, $this->userLevelCache)) {
            return $this->userLevelCache[$userId];
        }

        $levelId = DB::table('user')->where('id', $userId)->value('level_id');
        $userLevel = $levelId
            ? DB::table('user_level')->field('id, good_discount')->where('id', intval($levelId))->find()
            : null;

        $this->userLevelCache[$userId] = is_array($userLevel) ? $userLevel : null;
        return $this->userLevelCache[$userId];
    }
}
