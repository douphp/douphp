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

namespace Dou\Api\Controller\Pay;

use Dou\Api\Service\WxPayService;
use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\Payment\PaymentService;
use Dou\Core\Web\Http\Request;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 微信小程序支付 V2 异步回调。
 *
 * 仅服务 legacy `api/service/WxPayService`（out_trade_no = order_sn）的小程序支付；
 * 验签、商户与金额校验通过后复用 OrderStatusTransition 推进订单到 PAID，
 * 从而触发库存/积分/分销/等级等既有付款联动。
 */
class WxpayNotifyController
{
    /** @var PaymentService */
    private $paymentService;

    /**
     * @param PaymentService $paymentService
     */
    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * @param Request $request
     * @return void
     */
    public function notify(Request $request)
    {
        try {
            $body = (string) file_get_contents('php://input');
            if ($body === '' || strlen($body) > 1048576) {
                $this->respond(false, 'invalid body');
            }

            $data = $this->parseXml($body);
            if (!is_array($data)) {
                $this->respond(false, 'invalid xml');
            }

            $appid = (string) Config::get('param.miniprogram_appid', '');
            $mchId = (string) Config::get('param.miniprogram_pay_mch_id', '');
            $key = (string) Config::get('param.miniprogram_pay_key', '');
            if ($appid === '' || $mchId === '' || $key === '') {
                $this->respond(false, 'payment config missing');
            }

            $sign = isset($data['sign']) ? (string) $data['sign'] : '';
            unset($data['sign']);
            $signData = array();
            foreach ($data as $k => $v) {
                if ($v !== '' && $v !== null) {
                    $signData[$k] = $v;
                }
            }
            $service = new WxPayService('', '', $mchId, $key, '', '', 0);
            $expected = $service->getSign($signData);
            if ($sign === '' || !hash_equals($expected, strtoupper($sign))) {
                Log::error('wxpay notify sign mismatch', array('channel' => 'pay'));
                $this->respond(false, 'sign error');
            }

            if ((isset($data['return_code']) ? (string) $data['return_code'] : '') !== 'SUCCESS'
                || (isset($data['result_code']) ? (string) $data['result_code'] : '') !== 'SUCCESS'
            ) {
                $this->respond(false, 'trade fail');
            }
            if ((isset($data['appid']) ? (string) $data['appid'] : '') !== $appid
                || (isset($data['mch_id']) ? (string) $data['mch_id'] : '') !== $mchId
            ) {
                $this->respond(false, 'merchant mismatch');
            }

            $outTradeNo = isset($data['out_trade_no']) ? (string) $data['out_trade_no'] : '';
            if ($outTradeNo === '') {
                $this->respond(false, 'missing out_trade_no');
            }
            $transactionId = isset($data['transaction_id']) ? (string) $data['transaction_id'] : '';
            $paidFee = isset($data['total_fee']) ? (int) $data['total_fee'] : 0;
            $rawJson = function_exists('json_encode')
                ? (string) json_encode($data, JSON_UNESCAPED_UNICODE)
                : serialize($data);

            // 新路径：out_trade_no = dou_order_payment.payment_sn（小程序支付已补台账）
            $payment = $this->paymentService->findBySn($outTradeNo);
            if ($payment) {
                $expectedFee = (int) round((float) $payment['amount'] * 100);
                if ($expectedFee !== $paidFee) {
                    Log::error('wxpay notify amount mismatch', array(
                        'channel' => 'pay',
                        'payment_sn' => $outTradeNo,
                        'expected_fee' => $expectedFee,
                        'paid_fee' => $paidFee,
                    ));
                    $this->respond(false, 'amount mismatch');
                }
                if (!$this->paymentService->markSucceeded($outTradeNo, $transactionId, $rawJson, (float) $payment['amount'], 'CNY')) {
                    Log::error('wxpay notify markSucceeded failed', array(
                        'channel' => 'pay',
                        'payment_sn' => $outTradeNo,
                    ));
                    $this->respond(false, 'mark failed');
                }

                Log::info('wxpay notify paid', array(
                    'channel' => 'pay',
                    'payment_sn' => $outTradeNo,
                    'order_sn' => isset($payment['order_sn']) ? (string) $payment['order_sn'] : '',
                    'transaction_id' => $transactionId,
                ));
                $this->respond(true, 'OK');
            }

            // 兼容旧在途单：out_trade_no = order_sn，无 payment 台账
            $orderSn = $outTradeNo;
            $order = DB::table('order')->where('order_sn', $orderSn)->find();
            if (!is_array($order)) {
                $this->respond(false, 'order not found');
            }

            // 微信支付仅支持 CNY：旧在途单若为其它币种直接拒绝，避免按错误最小单位对账
            if (currency()->contextForOrder($order)['code'] !== 'CNY') {
                Log::error('wxpay notify currency not supported', array('channel' => 'pay', 'order_sn' => $orderSn));
                $this->respond(false, 'currency not supported');
            }

            $expectedFee = (int) round(currency()->orderAmount($order) * 100);
            if ($expectedFee !== $paidFee) {
                Log::error('wxpay notify amount mismatch', array('channel' => 'pay', 'order_sn' => $orderSn));
                $this->respond(false, 'amount mismatch');
            }

            $status = (string) $order['status'];
            if ($status === OrderStatus::PAID || $status === OrderStatus::COMPLETED) {
                $this->respond(true, 'OK');
            }
            if ($status !== OrderStatus::PENDING && $status !== OrderStatus::AWAITING_CONFIRMATION) {
                Log::error('wxpay notify order not payable', array('channel' => 'pay', 'order_sn' => $orderSn, 'status' => $status));
                $this->respond(false, 'order not payable');
            }

            app(\Dou\Core\Service\Order\OrderStatusTransition::class)->changeStatus(
                $orderSn,
                OrderStatus::PAID,
                'webhook',
                0,
                (string) $request->ip(),
                'wxpay_notify'
            );

            $current = (string) DB::table('order')->where('order_sn', $orderSn)->value('status');
            if ($current !== OrderStatus::PAID && $current !== OrderStatus::COMPLETED) {
                Log::error('wxpay notify order not advanced', array('channel' => 'pay', 'order_sn' => $orderSn, 'status' => $current));
                $this->respond(false, 'order not paid');
            }

            Log::info('wxpay notify paid', array(
                'channel' => 'pay',
                'order_sn' => $orderSn,
                'transaction_id' => $transactionId,
            ));
            $this->respond(true, 'OK');
        } catch (\Exception $e) {
            Log::error('wxpay notify exception: ' . $e->getMessage(), array(
                'channel' => 'pay',
                'exception' => get_class($e),
            ));
            $this->respond(false, 'exception');
        }
    }

    /**
     * @param string $body
     * @return array|null
     */
    private function parseXml($body)
    {
        if (!function_exists('simplexml_load_string')) {
            return null;
        }

        $disableLoader = function_exists('libxml_disable_entity_loader');
        $previous = false;
        if ($disableLoader) {
            $previous = libxml_disable_entity_loader(true);
        }
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        if ($disableLoader) {
            @libxml_disable_entity_loader($previous);
        }
        if (!$xml) {
            return null;
        }

        $data = array();
        foreach ($xml->children() as $child) {
            $data[$child->getName()] = (string) $child;
        }

        return $data;
    }

    /**
     * 微信要求回调响应为 XML；无论成功失败都返回 200。
     *
     * @param bool $success
     * @param string $message
     * @return void
     */
    private function respond($success, $message)
    {
        if (!headers_sent()) {
            header('Content-Type: application/xml; charset=utf-8');
        }
        echo '<?xml version="1.0" encoding="UTF-8"?><xml><return_code>'
            . ($success ? 'SUCCESS' : 'FAIL')
            . '</return_code><return_msg>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</return_msg></xml>';
        exit;
    }
}
