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

namespace Dou\Api\Service\Payment;

use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Infra\Plugin\Contract\MiniProgramPaymentProviderInterface;
use Dou\Core\Infra\Plugin\Dto\MiniProgramPaymentContext;
use Dou\Core\Infra\Plugin\Dto\PaymentRequest;
use Dou\Core\Infra\Plugin\PluginClient;
use Dou\Core\Service\Order\OrderCheckoutOptions;
use Dou\Core\Service\Order\OrderService as OrderCore;
use Dou\Core\Service\Payment\PaymentService;
use Dou\Front\Service\Order\CashierService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序支付业务（API 端）。
 *
 * 职责：
 *   - 按插件开关 + 小程序能力生成支付方式列表；
 *   - 校验订单/插件/币种后创建或复用 pending 支付腿；
 *   - 调 Provider 的 startMiniProgram() 返回原生动作描述；
 *   - 供 CashierController 与旧 WeixinController::pay 共用，避免两套逻辑。
 */
class MiniProgramPaymentService
{
    /** @var CashierService */
    private $cashierService;

    /** @var OrderCore */
    private $order;

    /** @var PaymentService */
    private $paymentService;

    /**
     * @param CashierService $cashierService
     * @param OrderCore $order
     * @param PaymentService $paymentService
     */
    public function __construct(
        CashierService $cashierService,
        OrderCore $order,
        PaymentService $paymentService
    ) {
        $this->cashierService = $cashierService;
        $this->order = $order;
        $this->paymentService = $paymentService;
    }

    /**
     * 小程序可用支付方式列表。
     *
     * @param array $order 已由 CashierService 装饰过的订单
     * @return array
     */
    public function buildPaymentList(array $order)
    {
        $currency = isset($order['currency_code']) ? (string) $order['currency_code'] : '';
        if ($currency === '') {
            $ctx = currency()->contextForOrder($order);
            $currency = isset($ctx['code']) ? (string) $ctx['code'] : '';
        }
        $module = isset($order['module']) ? (string) $order['module'] : '';

        $registry = Module::make('plugin.registry_aggregator');
        $methods = array();

        foreach ((array) $this->order->getPaymentList($currency, $module, PluginClient::MP) as $item) {
            $slug = isset($item['id']) ? (string) $item['id'] : '';
            if ($slug === '') {
                continue;
            }
            $provider = $this->provider($registry, $slug);
            if (!$provider instanceof MiniProgramPaymentProviderInterface) {
                continue;
            }

            $methods[] = array(
                'slug' => $slug,
                'name' => isset($item['name']) ? (string) $item['name'] : $slug,
                'icon' => isset($item['image']) ? (string) $item['image'] : '',
                'default' => false,
            );
        }

        // 小程序优先展示微信支付，离线付款作为兜底；其余保持原始顺序。
        $priority = array('wxpay' => 0, 'offlinepay' => 1);
        foreach ($methods as $index => $method) {
            $methods[$index]['_sort'] = $index;
        }
        usort($methods, function ($a, $b) use ($priority) {
            $pa = isset($priority[$a['slug']]) ? $priority[$a['slug']] : 99;
            $pb = isset($priority[$b['slug']]) ? $priority[$b['slug']] : 99;
            if ($pa !== $pb) {
                return $pa < $pb ? -1 : 1;
            }
            if ($a['_sort'] === $b['_sort']) {
                return 0;
            }

            return $a['_sort'] < $b['_sort'] ? -1 : 1;
        });

        foreach ($methods as $index => $method) {
            unset($methods[$index]['_sort']);
            $methods[$index]['default'] = $index === 0;
        }

        return $methods;
    }

    /**
     * 发起小程序支付。
     *
     * @param int $userId
     * @param string $orderSn
     * @param string $payId
     * @return array 原生动作描述
     * @throws DomainException
     */
    public function start($userId, $orderSn, $payId)
    {
        $userId = (int) $userId;
        $orderSn = (string) $orderSn;
        $payId = strtolower(trim((string) $payId));

        if ($payId === '' || preg_match('/^[a-z0-9_-]+$/', $payId) !== 1) {
            throw new DomainException(lang('illegal'));
        }

        $order = DB::table('order')
            ->where('order_sn', $orderSn)
            ->where('user_id', $userId)
            ->find();
        if (!is_array($order)) {
            throw new DomainException(lang('order_not_exist'));
        }
        if ((string) $order['status'] !== OrderStatus::PENDING) {
            throw new DomainException(lang('order_status_cannot_pay'));
        }

        $order = $this->cashierService->getPayOrder($userId, $orderSn);
        if (!is_array($order)) {
            throw new DomainException(lang('order_not_exist'));
        }

        $available = false;
        foreach ($this->buildPaymentList($order) as $item) {
            if (isset($item['slug']) && (string) $item['slug'] === $payId) {
                $available = true;
                break;
            }
        }
        if (!$available) {
            throw new DomainException(lang('order_payment_invalid', lang('illegal')));
        }

        $plugin = plugin()->getWithConfig($payId);
        if (!is_array($plugin)
            || !isset($plugin['plugin_group'])
            || (string) $plugin['plugin_group'] !== 'payment'
        ) {
            throw new DomainException(lang('order_payment_invalid', lang('illegal')));
        }

        $this->paymentService->closePendingWalletIntents($orderSn, 'wallet payment disabled');

        $remaining = $this->cashierService->remainingAmount($order);
        if ($remaining <= 0) {
            if ($this->paymentService->tryFinalizeOrder($orderSn)) {
                return array('type' => 'paid', 'payment_sn' => '');
            }
            throw new DomainException(lang('order_payment_invalid', lang('illegal')));
        }

        $orderCtx = currency()->contextForOrder($order);
        $timeoutMin = OrderCheckoutOptions::payTimeoutMinutes();
        $orderId = isset($order['order_id']) ? (int) $order['order_id'] : (int) $order['id'];

        // 同一金额的 pending 尝试复用，避免重复拉起多笔可支付订单。
        $existing = $this->paymentService->findActivePending($orderId);
        if (is_array($existing)
            && !$this->isExpired($existing)
            && (string) $existing['gateway'] === $payId
            && abs((float) $existing['amount'] - $remaining) < 0.005
        ) {
            $paymentSn = (string) $existing['payment_sn'];
        } else {
            // 仅关闭同一网关的旧尝试；不同网关可能已在第三方侧下单，不能贸然关闭。
            if (is_array($existing) && (string) $existing['gateway'] === $payId) {
                $this->paymentService->markClosed((string) $existing['payment_sn'], 'payment attempt replaced');
            }
            $payment = $this->paymentService->createForOrder(
                $orderId,
                (string) $order['order_sn'],
                $payId,
                $remaining,
                time() + $timeoutMin * 60,
                isset($orderCtx['code']) ? $orderCtx['code'] : '',
                currency()->toBase($remaining, $orderCtx)
            );
            $paymentSn = (string) $payment['payment_sn'];
        }

        $registry = Module::make('plugin.registry_aggregator');
        $provider = $this->provider($registry, $payId);
        if (!$provider instanceof MiniProgramPaymentProviderInterface) {
            $this->paymentService->markClosed($paymentSn, 'provider not mini program capable');
            throw new DomainException(lang('order_payment_invalid', lang('illegal')));
        }

        $appid = (string) Config::get('param.miniprogram_appid', '');
        $openid = (string) DB::table('user_sns')
            ->where('user_id', $userId)
            ->where('group', 'wxlogin')
            ->where('apptype', 'api')
            ->value('openid');
        $context = new MiniProgramPaymentContext($openid, $appid, $userId);

        try {
            $action = $provider->startMiniProgram(
                new PaymentRequest(
                    $payId,
                    $paymentSn,
                    (string) $order['order_sn'],
                    $remaining,
                    isset($orderCtx['code']) ? $orderCtx['code'] : ''
                ),
                $context
            );
        } catch (\Exception $e) {
            throw $this->providerFailure($e, $paymentSn, $orderSn, $payId);
        } catch (\Throwable $e) {
            throw $this->providerFailure($e, $paymentSn, $orderSn, $payId);
        }

        if (!is_array($action) || empty($action['type'])) {
            $this->paymentService->markClosed($paymentSn, 'provider start returned empty');
            throw new DomainException(lang('order_payment_start_failed'));
        }
        if ((string) $action['type'] === 'none') {
            $this->paymentService->markClosed($paymentSn, 'provider returned none');
            throw new DomainException(lang('order_payment_start_failed'));
        }

        // 网关启动成功后再落支付方式，避免失败时残留错误 pay_id。
        $this->order->writePayId((string) $order['order_sn'], $payId);

        if (!isset($action['payment_sn']) || (string) $action['payment_sn'] === '') {
            $action['payment_sn'] = $paymentSn;
        }

        return $action;
    }

    /**
     * 统一处理 Provider 启动失败：关闭支付腿、记日志，返回可抛出的异常。
     *
     * @param \Exception|\Throwable $e
     * @param string $paymentSn
     * @param string $orderSn
     * @param string $payId
     * @return DomainException
     */
    private function providerFailure($e, $paymentSn, $orderSn, $payId)
    {
        $this->paymentService->markClosed($paymentSn, 'provider start failed: ' . $e->getMessage());
        Log::error('mini program payment provider start failed', array(
            'channel' => 'order',
            'module' => 'order',
            'payload' => array(
                'order_sn' => $orderSn,
                'pay_id' => $payId,
                'payment_sn' => $paymentSn,
                'error' => $e->getMessage(),
            ),
        ));

        return $e instanceof DomainException ? $e : new DomainException(lang('order_payment_start_failed'));
    }

    /**
     * @param mixed $registry
     * @param string $slug
     * @return mixed
     */
    private function provider($registry, $slug)
    {
        if (!is_object($registry) || !method_exists($registry, 'payment')) {
            return null;
        }
        $paymentRegistry = $registry->payment();
        if (!is_object($paymentRegistry) || !method_exists($paymentRegistry, 'provider')) {
            return null;
        }

        return $paymentRegistry->provider($slug);
    }

    /**
     * @param array $payment
     * @return bool
     */
    private function isExpired(array $payment)
    {
        if (empty($payment['expired_at'])) {
            return false;
        }
        $ts = strtotime((string) $payment['expired_at']);

        return $ts !== false && $ts <= time();
    }
}
