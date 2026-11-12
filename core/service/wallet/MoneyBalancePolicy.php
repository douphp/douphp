<?php

/**
 * DouPHP® — MIT 协议，完整声明见根目录 LICENSE。
 * 余额资金性质：充值储值不可提现，奖励可提现，线下消费优先使用储值。
 */

namespace Dou\Core\Service\Wallet;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

class MoneyBalancePolicy
{
    /** 流水按 id 升序；线下消费先用储值再用奖励，提现只消耗奖励。 */
    public static function summarize(array $rows, $balance)
    {
        $reward = 0;
        $withdrawals = array();
        foreach ($rows as $row) {
            $amount = (int) round((float) $row['money'] * 100);
            $after = (int) round((float) $row['total'] * 100);
            // 只有明确的奖励入账才增加提现资格。
            $total = max(0, $after - $amount);
            $reward = min($reward, $total);
            $action = (string) $row['action'];
            $source = isset($row['from']) ? (string) $row['from'] : '';
            if ($amount > 0) {
                if (in_array($action, array('direct_reward', 'indirect_reward', 'share'), true)) {
                    $reward += $amount;
                } elseif ($action === 'withdraw_reject') {
                    $restore = isset($withdrawals[$source]) ? min($amount, $withdrawals[$source]) : 0;
                    $reward += $restore;
                    $withdrawals[$source] = isset($withdrawals[$source]) ? $withdrawals[$source] - $restore : 0;
                }
            } elseif ($amount < 0) {
                $debit = -$amount;
                if ($action === 'withdraw' || $action === 'order_refund_reward') {
                    $usedReward = min($reward, $debit);
                } else {
                    $usedReward = min($reward, max(0, $debit - ($total - $reward)));
                }
                $reward -= $usedReward;
                if ($action === 'withdraw') {
                    $withdrawals[$source] = $usedReward;
                }
            }
            $reward = max(0, min($reward, max(0, $after)));
        }
        $balance = max(0, (int) round((float) $balance * 100));
        $reward = min($reward, $balance);
        return array('withdrawable' => $reward / 100, 'stored' => ($balance - $reward) / 100);
    }
}
