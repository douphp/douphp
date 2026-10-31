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

namespace Dou\Core\Service\Wallet;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Event\Scene\SceneHandler;
use Dou\Core\Foundation\Event\Scene\SceneNames;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员积分与余额台账写入、充值包订单入账。
 *
 * 余额/积分均以流水表为权威、dou_user_wallet 为快照。所有写入必须满足：
 * - 金额归一且非 0；
 * - 以会员行为串行化点加锁，保证首次写入也能串行；
 * - 流水与快照写入结果必须校验，失败即回滚，不得静默返回成功。
 */
class WalletService extends BaseService implements SceneHandler
{
    /**
     * 场景处理入口（用于 order.paid.money）。
     *
     * @param string $scene
     * @param array $payload
     * @return void
     */
    public function handle($scene, array $payload)
    {
        if ($scene !== SceneNames::ORDER_PAID || !Config::get('features.money', false)) {
            return;
        }
        $this->creditMoneyPackageFromPaidOrder($payload);
    }

    /**
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * 写入积分流水（带行锁），并同步 dou_user_wallet.point_balance 快照。
     *
     * @param mixed $user_id 会员 ID。
     * @param mixed $action 动作标识。
     * @param mixed $point 变动积分（可负）。
     * @param string $from 关联来源。
     * @param string $operator_type 操作者类型 admin/user/work/system（默认 user，与列默认一致）。
     * @param mixed $operator_id 操作者 ID（system 传 0）。
     * @param string $source_type 业务来源类型 order_pay/sign/...
     * @param int $source_id 业务来源主键。
     * @param string $remark 备注。
     * @param string $ip 来源 IP。
     * @return bool
     */
    public function createPoint($user_id, $action, $point, $from = '', $operator_type = 'user', $operator_id = 0, $source_type = '', $source_id = 0, $remark = '', $ip = '')
    {
        if (!Config::get('features.point', false)) {
            return false;
        }

        $user_id = intval($user_id);
        if (!$user_id) {
            return false;
        }

        $point = intval($point);
        if ($point === 0) {
            return false;
        }

        try {
            DB::beginTransaction();

            if (!DB::table('user')->where('id', $user_id)->lock('FOR UPDATE')->value('id')) {
                DB::rollback();
                return false;
            }

            $last = DB::table('point')
                ->where('user_id', $user_id)
                ->order('id DESC')
                ->lock('FOR UPDATE')
                ->find();
            if (is_array($last)) {
                $total = (float) $last['total'];
            } else {
                $snapshot = DB::table('user_wallet')->where('id', $user_id)->value('point_balance');
                $total = ($snapshot === null || $snapshot === false) ? 0.0 : (float) $snapshot;
            }

            $total = $total + $point;
            if ($total < 0) {
                DB::rollback();
                return false;
            }

            $insertId = DB::table('point')->data(array(
                'user_id' => $user_id,
                'operator_type' => (string) $operator_type,
                'operator_id' => intval($operator_id),
                'action' => $action,
                'point' => $point,
                'total' => (int) $total,
                'from' => $from,
                'source_type' => (string) $source_type,
                'source_id' => intval($source_id),
                'remark' => (string) $remark,
                'ip' => (string) $ip,
                'created_at' => date('Y-m-d H:i:s'),
            ))->insert();

            if (!$insertId) {
                throw new \Exception('point ledger insert failed');
            }

            $this->upsertWallet($user_id, array('point_balance' => (int) $total));

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('wallet createPoint failed: ' . $e->getMessage(), array(
                'channel' => 'wallet',
                'payload' => array(
                    'user_id' => $user_id,
                    'action' => (string) $action,
                    'point' => $point,
                ),
            ));
            return false;
        }
    }

    /**
     * 写入余额流水（带行锁）。
     *
     * @param mixed $user_id 会员 ID（账户主体）。
     * @param string $operator_type 操作者类型 admin/user/work/system。
     * @param mixed $operator_id 操作者 ID（system 传 0）。
     * @param mixed $action 动作标识。
     * @param mixed $money 变动金额（可负）。
     * @param string $from 关联来源。
     * @param int $from_user_id 来源会员 ID。
     * @param string $source_type 业务来源类型 order_pay/order_refund/recharge/withdraw...
     * @param int $source_id 业务来源主键。
     * @param string $remark 备注。
     * @param string $ip 来源 IP。
     * @param array $extraFields 额外的流水字段（price/sale_price/sale_price_type/item_id 等）。
     * @return bool
     */
    public function createMoney($user_id, $operator_type, $operator_id, $action, $money, $from = '', $from_user_id = 0, $source_type = '', $source_id = 0, $remark = '', $ip = '', array $extraFields = array())
    {
        if (!Config::get('features.money', false)) {
            return false;
        }

        $user_id = intval($user_id);
        if (!$user_id) {
            return false;
        }

        // 金额统一到两位小数；低于分位视为 0，不再写入零变动流水
        $money = round((float) $money, 2);
        if (abs($money) < 0.005) {
            return false;
        }

        try {
            DB::beginTransaction();

            // 以会员行为串行化点：即使该会员还没有任何流水，也能保证首次写入并发安全
            if (!DB::table('user')->where('id', $user_id)->lock('FOR UPDATE')->value('id')) {
                DB::rollback();
                return false;
            }

            $last = DB::table('money')
                ->where('user_id', $user_id)
                ->order('id DESC')
                ->lock('FOR UPDATE')
                ->find();
            if (is_array($last)) {
                $total = (float) $last['total'];
            } else {
                // 无流水时回退到快照，避免历史快照余额被首次写入从 0 覆盖
                $snapshot = DB::table('user_wallet')->where('id', $user_id)->value('money_balance');
                $total = ($snapshot === null || $snapshot === false) ? 0.0 : (float) $snapshot;
            }

            $total = round($total + $money, 2);
            if ($total < 0) {
                DB::rollback();
                return false;
            }

            $row = array(
                'user_id' => $user_id,
                'operator_type' => $operator_type,
                'operator_id' => intval($operator_id),
                'action' => $action,
                'money' => $money,
                'total' => $total,
                'from' => $from,
                'from_user_id' => intval($from_user_id),
                'source_type' => (string) $source_type,
                'source_id' => intval($source_id),
                'remark' => (string) $remark,
                'ip' => (string) $ip,
                'created_at' => date('Y-m-d H:i:s'),
            );
            foreach ($extraFields as $field => $value) {
                if (!array_key_exists($field, $row)) {
                    $row[$field] = $value;
                }
            }

            $insertId = DB::table('money')->data($row)->insert();
            if (!$insertId) {
                throw new \Exception('money ledger insert failed');
            }

            $walletFields = array('money_balance' => (float) $total);
            if (in_array($action, array('direct_reward', 'indirect_reward'), true) && $money > 0) {
                $walletFields['total_promote'] = round($this->currentWalletDecimal($user_id, 'total_promote') + $money, 2);
            }
            $this->upsertWallet($user_id, $walletFields);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('wallet createMoney failed: ' . $e->getMessage(), array(
                'channel' => 'wallet',
                'payload' => array(
                    'user_id' => $user_id,
                    'action' => (string) $action,
                    'money' => $money,
                    'from' => (string) $from,
                ),
            ));
            return false;
        }
    }

    /**
     * 订单已付场景：充值包入账（按订单 + 动作 + 套餐行防重）。
     *
     * @param array $payload 须含 order_item（数组）。
     * @return void
     */
    public function creditMoneyPackageFromPaidOrder(array $payload)
    {
        if (!Config::get('features.money', false)) {
            return;
        }

        if (empty($payload['order_item']) || !is_array($payload['order_item'])) {
            return;
        }

        $order_item = $payload['order_item'];
        if (!isset($order_item['module']) || $order_item['module'] != 'money_package') {
            return;
        }

        $orderId = isset($order_item['order_id']) ? (int) $order_item['order_id'] : 0;
        $itemId = isset($order_item['item_id']) ? (int) $order_item['item_id'] : 0;
        $userId = isset($order_item['user_id']) ? (int) $order_item['user_id'] : 0;
        if ($orderId <= 0 || $itemId <= 0 || $userId <= 0) {
            Log::critical('money package credit invalid order item', array(
                'channel' => 'wallet',
                'payload' => $order_item,
            ));
            return;
        }

        try {
            DB::beginTransaction();

            // 锁定订单行：付款事件在订单事务提交后派发，这里不会与订单状态更新互相阻塞
            $order = DB::table('order')->where('id', $orderId)->lock('FOR UPDATE')->find();
            if (!$order) {
                DB::rollback();
                Log::critical('money package credit order not found', array(
                    'channel' => 'wallet',
                    'payload' => array('order_id' => $orderId),
                ));
                return;
            }
            $orderSn = (string) $order['order_sn'];

            // 只认 money_package 入账流水，不能把同单的 order_pay/order_refund 当作已入账
            $exists = DB::table('money')
                ->where('user_id', $userId)
                ->where('from', $orderSn)
                ->where('action', 'money_package')
                ->where('item_id', $itemId)
                ->exists();
            if ($exists) {
                DB::commit();
                return;
            }

            $package = DB::table('money_package')->where('id', $itemId)->find();
            if (!$package || !isset($package['money'])) {
                DB::rollback();
                Log::critical('money package credit package missing', array(
                    'channel' => 'wallet',
                    'payload' => array('order_id' => $orderId, 'item_id' => $itemId),
                ));
                return;
            }

            $faceValue = round((float) $package['money'], 2);
            $number = isset($order_item['item_number']) ? max(1, (int) $order_item['item_number']) : 1;
            $amount = round($faceValue * $number, 2);
            if ($faceValue <= 0 || $amount <= 0) {
                DB::rollback();
                Log::critical('money package credit amount invalid', array(
                    'channel' => 'wallet',
                    'payload' => array('order_id' => $orderId, 'item_id' => $itemId, 'money' => $faceValue, 'number' => $number),
                ));
                return;
            }

            $ok = $this->createMoney(
                $userId,
                'system',
                0,
                'money_package',
                $amount,
                $orderSn,
                0,
                'recharge',
                $orderId,
                lang('money_action_money_package'),
                '',
                array(
                    'price' => isset($order_item['price']) ? round((float) $order_item['price'], 2) : 0,
                    'sale_price' => isset($order_item['sale_price']) ? round((float) $order_item['sale_price'], 2) : 0,
                    'sale_price_type' => isset($order_item['sale_price_type']) ? (string) $order_item['sale_price_type'] : 'original',
                    'item_id' => $itemId,
                )
            );
            if (!$ok) {
                throw new \Exception('money package credit write failed');
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            Log::critical('money package credit failed: ' . $e->getMessage(), array(
                'channel' => 'wallet',
                'payload' => array(
                    'order_id' => $orderId,
                    'item_id' => $itemId,
                    'user_id' => $userId,
                ),
            ));
        }
    }

    /**
     * 读取会员钱包快照某个金额字段当前值（不存在按 0）。
     *
     * @param int $userId
     * @param string $field
     * @return float
     */
    private function currentWalletDecimal($userId, $field)
    {
        $value = DB::table('user_wallet')->where('id', (int) $userId)->value($field);

        return $value ? (float) $value : 0.0;
    }

    /**
     * 以 user_id 为主键 upsert dou_user_wallet 快照。
     *
     * 该表为账户视图聚合缓存，流水以 dou_money / dou_point 为权威；
     * 故此处按「存在则更新、否则插入」最终一致地刷新冷字段，缺省值兜底首行。
     * 写入失败必须抛异常，由调用方回滚流水，避免快照与台账分裂。
     *
     * @param int $userId
     * @param array $fields 待写入字段（money_balance/point_balance/total_consumption/total_promote 等）
     * @return void
     * @throws \Exception
     */
    private function upsertWallet($userId, array $fields)
    {
        $userId = (int) $userId;
        if ($userId <= 0 || empty($fields)) {
            return;
        }

        $fields['updated_at'] = date('Y-m-d H:i:s');

        if (DB::table('user_wallet')->where('id', $userId)->exists()) {
            if (DB::table('user_wallet')->where('id', $userId)->data($fields)->update() === false) {
                throw new \Exception('user_wallet update failed');
            }
            return;
        }

        $row = array(
            'id' => $userId,
            'money_balance' => 0.00,
            'money_freeze' => 0.00,
            'point_balance' => 0,
            'point_freeze' => 0,
            'total_consumption' => 0.00,
            'total_promote' => 0.00,
        );
        foreach ($fields as $key => $value) {
            $row[$key] = $value;
        }
        // user_wallet 主键非自增，insert() 的 insertId 恒为 0，不能用返回值判定成功
        if (DB::table('user_wallet')->data($row)->insert() === false
            || !DB::table('user_wallet')->where('id', $userId)->exists()
        ) {
            throw new \Exception('user_wallet insert failed');
        }
    }
}
