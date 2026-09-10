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
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员积分与余额台账写入、充值包订单入账。
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

        $total = DB::table('point')
            ->where('user_id', $user_id)
            ->order('id DESC')
            ->lock('FOR UPDATE')
            ->value('total');

        $total = ($total ? $total : 0) + $point;

        if ($total < 0) {
            return false;
        }

        $created_at = time();
        DB::table('point')->data(array(
            'user_id' => $user_id,
            'operator_type' => (string) $operator_type,
            'operator_id' => intval($operator_id),
            'action' => $action,
            'point' => $point,
            'total' => $total,
            'from' => $from,
            'source_type' => (string) $source_type,
            'source_id' => intval($source_id),
            'remark' => (string) $remark,
            'ip' => (string) $ip,
            'created_at' => $created_at,
        ))->insert();

        $this->upsertWallet($user_id, array('point_balance' => (int) $total));

        return true;
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
     * @return bool
     */
    public function createMoney($user_id, $operator_type, $operator_id, $action, $money, $from = '', $from_user_id = 0, $source_type = '', $source_id = 0)
    {
        if (!Config::get('features.money', false)) {
            return false;
        }

        $user_id = intval($user_id);
        if (!$user_id) {
            return false;
        }

        $total = DB::table('money')
            ->where('user_id', $user_id)
            ->order('id DESC')
            ->lock('FOR UPDATE')
            ->value('total');

        $total = ($total ? $total : 0) + $money;

        if ($total < 0) {
            return false;
        }

        $created_at = time();
        DB::table('money')->data(array(
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
            'created_at' => $created_at,
        ))->insert();

        $walletFields = array('money_balance' => (float) $total);
        if (in_array($action, array('direct_reward', 'indirect_reward'), true) && $money > 0) {
            $walletFields['total_promote'] = $this->currentWalletDecimal($user_id, 'total_promote') + (float) $money;
        }
        $this->upsertWallet($user_id, $walletFields);

        return true;
    }

    /**
     * 订单已付场景：充值包入账（按订单号防重）。
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
        if ($order_item['module'] != 'money_package') {
            return;
        }

        $order_sn = DB::table('order')->where('id', $order_item['order_id'])->value('order_sn');

        if (DB::table('money')->where('from', $order_sn)->exists()) {
            return;
        }

        $money = DB::table('money_package')->where('id', $order_item['item_id'])->value('money');
        $total = DB::table('money')->where('user_id', $order_item['user_id'])->order('id DESC')->value('total');
        $total = ($total ? $total : 0) + $money;

        DB::table('money')->data(array(
            'user_id' => $order_item['user_id'],
            'operator_type' => 'system',
            'operator_id' => 0,
            'action' => 'money_package',
            'money' => $money,
            'total' => $total,
            'price' => $order_item['price'],
            'sale_price' => $order_item['sale_price'],
            'sale_price_type' => $order_item['sale_price_type'],
            'item_id' => $order_item['item_id'],
            'from' => $order_sn,
            'source_type' => 'recharge',
            'source_id' => (int) $order_item['order_id'],
            'ip' => '',
            'created_at' => $order_item['created_at'],
        ))->insert();

        $this->upsertWallet((int) $order_item['user_id'], array('money_balance' => (float) $total));
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
     *
     * @param int $userId
     * @param array $fields 待写入字段（money_balance/point_balance/total_consumption/total_promote 等）
     * @return void
     */
    private function upsertWallet($userId, array $fields)
    {
        $userId = (int) $userId;
        if ($userId <= 0 || empty($fields)) {
            return;
        }

        $fields['updated_at'] = time();

        if (DB::table('user_wallet')->where('id', $userId)->exists()) {
            DB::table('user_wallet')->where('id', $userId)->data($fields)->update();
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
        DB::table('user_wallet')->data($row)->insert();
    }
}
