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

namespace Dou\Core\Service\Aigc;

use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Event\Scene\SceneHandler;
use Dou\Core\Foundation\Event\Scene\SceneNames;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * chat 套餐订阅：order.paid 场景处理器。
 *
 * 会员购买 chat 套餐（订单 module = aigc_package）支付成功后，
 * 写入订阅记录并累加会员配额（tokens / requests）。
 */
class SubscriptionService extends BaseService implements SceneHandler
{
    /**
     * order.paid 场景回调。
     *
     * @param string $scene 场景名
     * @param array $payload 含 order_item
     * @return void
     */
    public function handle($scene, array $payload)
    {
        if ($scene !== SceneNames::ORDER_PAID || empty($payload['order_item']) || !is_array($payload['order_item'])) {
            return;
        }

        $orderItem = $payload['order_item'];
        if ($orderItem['module'] !== 'aigc_package') {
            return;
        }

        $orderSn = DB::table('order')->where('id', $orderItem['order_id'])->value('order_sn');
        if (DB::table('aigc_subscription')->where('order_sn', $orderSn)->exists()) {
            return;
        }

        $package = DB::table('aigc_package')->where('id', $orderItem['item_id'])->find();
        if (!$package) {
            return;
        }

        // 已有有效订阅时顺延叠加（从其结束时间起算）
        $existing = DB::table('aigc_subscription')
            ->where('user_id', $orderItem['user_id'])
            ->where('order_status', OrderStatus::COMPLETED)
            ->where('end_at', '>', date('Y-m-d H:i:s'))
            ->order('end_at DESC')
            ->find();

        $now = time();
        $startTime = $now;
        if ($existing) {
            $endTs = Util::toTimestamp($existing['end_at']);
            if ($endTs !== null && $endTs > $now) {
                $startTime = $endTs;
            }
        }
        $endTime = strtotime('+' . $package['day'] . ' day', $startTime);

        // ip 由支付通知回调触发，无真实用户 IP，统一留空
        DB::table('aigc_subscription')->data(array(
            'user_id' => $orderItem['user_id'],
            'package_id' => $orderItem['item_id'],
            'order_sn' => $orderSn,
            'price' => $orderItem['price'],
            'sale_price' => $orderItem['sale_price'],
            'sale_price_type' => $orderItem['sale_price_type'],
            'tokens_limit' => $package['tokens_limit'],
            'tokens_used' => 0,
            'requests_limit' => $package['requests_limit'],
            'requests_used' => 0,
            'start_at' => date('Y-m-d H:i:s', $startTime),
            'end_at' => date('Y-m-d H:i:s', $endTime),
            'ip' => '',
            'order_status' => $orderItem['order_status'],
            'created_at' => $orderItem['created_at'],
        ))->insert();

        $this->grantQuota((int) $orderItem['user_id'], 'tokens', (int) $package['tokens_limit'], $endTime);
        $this->grantQuota((int) $orderItem['user_id'], 'requests', (int) $package['requests_limit'], $endTime);
    }

    /**
     * 累加单一类型配额并顺延过期时间；无记录时新建。
     *
     * 旧配额已过期时先丢弃旧周期的 total_limit/used_amount，避免未用量滚入
     * 新周期；未过期则保持叠加与用量连续。
     *
     * @param int $userId
     * @param string $quotaType tokens|requests
     * @param int $limit 套餐限额（0 = 不限）
     * @param int $expiresAt 过期时间戳
     * @return void
     */
    private function grantQuota($userId, $quotaType, $limit, $expiresAt)
    {
        $expiresAtStr = date('Y-m-d H:i:s', $expiresAt);
        $now = time();

        $quota = DB::table('aigc_quota')
            ->where('user_id', $userId)
            ->where('quota_type', $quotaType)
            ->find();

        if ($quota) {
            $expired = !empty($quota['expires_at']) && strtotime($quota['expires_at']) <= $now;
            $baseLimit = $expired ? 0 : (int) $quota['total_limit'];
            $baseUsed = $expired ? 0 : (int) $quota['used_amount'];
            $newLimit = $limit > 0 ? $baseLimit + $limit : 0;
            $newExpires = (!$expired && !empty($quota['expires_at']) && strtotime($quota['expires_at']) > $expiresAt)
                ? $quota['expires_at']
                : $expiresAtStr;

            DB::table('aigc_quota')
                ->where('id', $quota['id'])
                ->data(array(
                    'total_limit' => $newLimit,
                    'used_amount' => $baseUsed,
                    'expires_at' => $newExpires,
                    'updated_at' => date('Y-m-d H:i:s'),
                ))->update();

            return;
        }

        DB::table('aigc_quota')->data(array(
            'user_id' => $userId,
            'quota_type' => $quotaType,
            'total_limit' => $limit,
            'used_amount' => 0,
            'reset_cycle' => 'never',
            'expires_at' => $expiresAtStr,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ))->insert();
    }
}
