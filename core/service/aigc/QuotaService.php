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
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * aigc 会员配额（aigc_quota / aigc_subscription）：状态查询、可用性校验、扣减与退还。
 *
 * 配额发放由 {@see SubscriptionService}（order.paid 场景）负责；本类只读写「已发配额」。
 */
class QuotaService extends BaseService
{
    /**
     * 取会员某类型的有效配额行（未过期）。
     *
     * @param int $userId
     * @param string $quotaType tokens|requests
     * @return array|null
     */
    public function getQuota($userId, $quotaType)
    {
        if ((int) $userId <= 0) {
            return null;
        }

        $quota = DB::table('aigc_quota')
            ->where('user_id', (int) $userId)
            ->where('quota_type', $quotaType)
            ->where(function ($q) {
                $q->where('expires_at', null)
                    ->whereOr('expires_at', '>', date('Y-m-d H:i:s'));
            })
            ->find();
        if (!$quota) {
            return null;
        }

        return $this->applyResetCycle($quota);
    }

    /**
     * 按 reset_cycle 惰性清零已用量。
     *
     * 周期起点以自然日/周一/月初/年初为准；last_reset_at 早于当前周期起点（或为空）
     * 时清零 used_amount 并写回 last_reset_at。并发下重复清零无害。
     *
     * @param array $quota 配额行
     * @return array 可能已清零的配额行
     */
    private function applyResetCycle(array $quota)
    {
        $cycle = isset($quota['reset_cycle']) ? (string) $quota['reset_cycle'] : 'never';
        if ($cycle === 'never') {
            return $quota;
        }

        $periodStart = $this->periodStart($cycle);
        if ($periodStart === null) {
            return $quota;
        }

        $lastReset = !empty($quota['last_reset_at']) ? strtotime($quota['last_reset_at']) : null;
        if ($lastReset !== null && $lastReset >= $periodStart) {
            return $quota;
        }

        $now = date('Y-m-d H:i:s');
        DB::table('aigc_quota')
            ->where('id', (int) $quota['id'])
            ->data(array(
                'used_amount' => 0,
                'last_reset_at' => $now,
            ))
            ->update();

        $quota['used_amount'] = 0;
        $quota['last_reset_at'] = $now;

        return $quota;
    }

    /**
     * 当前重置周期起点时间戳。
     *
     * @param string $cycle daily|weekly|monthly|yearly
     * @return int|null
     */
    private function periodStart($cycle)
    {
        switch ($cycle) {
            case 'daily':
                return strtotime('today');
            case 'weekly':
                return strtotime('monday this week');
            case 'monthly':
                return strtotime(date('Y-m-01 00:00:00'));
            case 'yearly':
                return strtotime(date('Y-01-01 00:00:00'));
        }

        return null;
    }

    /**
     * 校验会员是否还有可用额度。
     *
     * @param int $userId
     * @return array {valid: bool, message: string}
     */
    public function check($userId)
    {
        if ((int) $userId <= 0) {
            return array('valid' => false, 'message' => lang('aigc_tips_need_login', '请先登录'));
        }

        $tokensQuota = $this->getQuota($userId, 'tokens');
        $requestsQuota = $this->getQuota($userId, 'requests');

        // tokens / requests 任一存在即为有效配额；后台允许只开通其中一种
        if (!$tokensQuota && !$requestsQuota) {
            return array('valid' => false, 'message' => lang('aigc_quota_none'));
        }

        if ($tokensQuota && $tokensQuota['total_limit'] > 0 && $tokensQuota['used_amount'] >= $tokensQuota['total_limit']) {
            return array('valid' => false, 'message' => lang('aigc_quota_tokens_out'));
        }

        if ($requestsQuota && $requestsQuota['total_limit'] > 0 && $requestsQuota['used_amount'] >= $requestsQuota['total_limit']) {
            return array('valid' => false, 'message' => lang('aigc_quota_requests_out'));
        }

        return array('valid' => true, 'message' => '');
    }

    /**
     * 扣减配额（tokens + requests），并同步累加最近一笔有效订阅的用量展示字段。
     *
     * 全部走 SQL 原子自增（`used_amount = used_amount + N`），消除读改写竞态。
     *
     * @param int $userId
     * @param int $tokens
     * @param int $requests
     * @return void
     */
    public function deduct($userId, $tokens, $requests = 1)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return;
        }

        $tokensQuota = $this->getQuota($userId, 'tokens');
        if ($tokensQuota) {
            DB::table('aigc_quota')->where('id', $tokensQuota['id'])->inc('used_amount', (int) $tokens);
        }

        $requestsQuota = $this->getQuota($userId, 'requests');
        if ($requestsQuota) {
            DB::table('aigc_quota')->where('id', $requestsQuota['id'])->inc('used_amount', (int) $requests);
        }

        $subscription = $this->getActiveSubscription($userId);
        if ($subscription) {
            DB::table('aigc_subscription')->where('id', $subscription['id'])->inc('tokens_used', (int) $tokens);
            DB::table('aigc_subscription')->where('id', $subscription['id'])->inc('requests_used', (int) $requests);
        }
    }

    /**
     * 退还配额（任务失败 / 超时时回滚已扣次数）。
     *
     * 全部走 GREATEST(used - N, 0) 原子扣减，避免并发下退成负数。
     *
     * @param int $userId
     * @param int $tokens
     * @param int $requests
     * @return void
     */
    public function refund($userId, $tokens = 0, $requests = 1)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return;
        }

        $tokensQuota = $this->getQuota($userId, 'tokens');
        if ($tokensQuota && (int) $tokens > 0) {
            DB::stmtExecute(
                'UPDATE ' . DB::tableName('aigc_quota') . ' SET used_amount = GREATEST(used_amount - ?, 0) WHERE id = ?',
                array((int) $tokens, (int) $tokensQuota['id']),
                'ii'
            );
        }

        $requestsQuota = $this->getQuota($userId, 'requests');
        if ($requestsQuota && (int) $requests > 0) {
            DB::stmtExecute(
                'UPDATE ' . DB::tableName('aigc_quota') . ' SET used_amount = GREATEST(used_amount - ?, 0) WHERE id = ?',
                array((int) $requests, (int) $requestsQuota['id']),
                'ii'
            );
        }

        $subscription = $this->getActiveSubscription($userId);
        if ($subscription) {
            DB::stmtExecute(
                'UPDATE ' . DB::tableName('aigc_subscription')
                . ' SET tokens_used = GREATEST(tokens_used - ?, 0), requests_used = GREATEST(requests_used - ?, 0) WHERE id = ?',
                array((int) $tokens, (int) $requests, (int) $subscription['id']),
                'iii'
            );
        }
    }

    /**
     * 免费应用每日限额校验：按当天 aigc_usage_log 聚合（自然日重置）。
     *
     * @param int $userId
     * @param int $chatId
     * @param string $limitType requests|tokens
     * @param int $limitValue 0 = 不限
     * @return array {valid: bool, message: string}
     */
    public function checkFreeAppDaily($userId, $chatId, $limitType, $limitValue)
    {
        $limitValue = (int) $limitValue;
        if ($limitValue <= 0) {
            return array('valid' => true, 'message' => '');
        }

        $query = DB::table('aigc_usage_log')
            ->where('user_id', (int) $userId)
            ->where('app_id', (int) $chatId)
            ->where('created_at', '>=', date('Y-m-d 00:00:00'));

        if ($limitType === 'tokens') {
            $used = (int) $query->sum('total_tokens');
            if ($used >= $limitValue) {
                return array('valid' => false, 'message' => lang('aigc_free_limit_tokens_out'));
            }
        } else {
            $used = (int) $query->count();
            if ($used >= $limitValue) {
                return array('valid' => false, 'message' => lang('aigc_free_limit_requests_out'));
            }
        }

        return array('valid' => true, 'message' => '');
    }

    /**
     * 最近一笔有效订阅（已支付且未到期）。
     *
     * @param int $userId
     * @return array|null
     */
    public function getActiveSubscription($userId)
    {
        if ((int) $userId <= 0) {
            return null;
        }

        return DB::table('aigc_subscription')
            ->where('user_id', (int) $userId)
            ->where('order_status', OrderStatus::COMPLETED)
            ->where('end_at', '>', date('Y-m-d H:i:s'))
            ->order('end_at DESC')
            ->find();
    }

    /**
     * 配额状态汇总（喂前台页面 / JSON）。
     *
     * @param int $userId
     * @return array
     */
    public function status($userId)
    {
        $tokensQuota = $this->getQuota($userId, 'tokens');
        $requestsQuota = $this->getQuota($userId, 'requests');

        if (!$tokensQuota && !$requestsQuota) {
            return array(
                'has_subscription' => false,
                'package_name' => '',
                'tokens_remaining' => 0,
                'tokens_limit' => 0,
                'tokens_used' => 0,
                'tokens_percent' => 0,
                'requests_remaining' => 0,
                'requests_limit' => 0,
                'requests_used' => 0,
                'requests_percent' => 0,
                'expires_at' => null,
                'expires_format' => lang('aigc_no_subscription', '暂无订阅'),
            );
        }

        $packageName = '';
        $subscription = $this->getActiveSubscription($userId);
        if ($subscription) {
            $packageName = (string) DB::table('aigc_package')->where('id', $subscription['package_id'])->value('name');
        }

        $tokensLimit = $tokensQuota ? (int) $tokensQuota['total_limit'] : 0;
        $tokensUsed = $tokensQuota ? (int) $tokensQuota['used_amount'] : 0;
        $requestsLimit = $requestsQuota ? (int) $requestsQuota['total_limit'] : 0;
        $requestsUsed = $requestsQuota ? (int) $requestsQuota['used_amount'] : 0;

        // 过期时间取主配额行（优先 tokens，其次 requests）
        $primary = $tokensQuota ? $tokensQuota : $requestsQuota;
        $expiresAt = !empty($primary['expires_at']) ? strtotime($primary['expires_at']) : null;

        return array(
            'has_subscription' => true,
            'package_name' => $packageName,
            'tokens_remaining' => $tokensLimit > 0 ? max(0, $tokensLimit - $tokensUsed) : -1,
            'tokens_limit' => $tokensLimit,
            'tokens_used' => $tokensUsed,
            'tokens_percent' => $tokensLimit > 0 ? round($tokensUsed / $tokensLimit * 100, 1) : 0,
            'requests_remaining' => $requestsLimit > 0 ? max(0, $requestsLimit - $requestsUsed) : -1,
            'requests_limit' => $requestsLimit,
            'requests_used' => $requestsUsed,
            'requests_percent' => $requestsLimit > 0 ? round($requestsUsed / $requestsLimit * 100, 1) : 0,
            'expires_at' => $expiresAt,
            'expires_format' => $expiresAt ? date('Y-m-d', $expiresAt) : lang('aigc_quota_forever'),
        );
    }
}
