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

use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Infra\Plugin\Dto\PaymentCallbackPayload;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 支付异步回调统一入口（route=pay/{plugin_id}/notify）。
 *
 * API 端不再为单个网关维护专属回调控制器：按 plugin_id 解析支付 Provider，
 * 由插件自行验签与处理业务，控制器只负责输出原始响应（XML / 纯文本）。
 */
class PaymentNotifyController
{
    /**
     * @param Request $request
     * @return Response
     */
    public function notify(Request $request)
    {
        $pluginId = strtolower(trim((string) $request->route('plugin_id', '')));
        if ($pluginId === '' || preg_match('/^[a-z0-9_-]+$/', $pluginId) !== 1) {
            return $this->respond('fail');
        }

        $provider = $this->provider($pluginId);
        if ($provider === null) {
            return $this->respond('fail');
        }

        $data = array_merge((array) $request->get(), (array) $request->post());

        try {
            $result = (string) $provider->notify(new PaymentCallbackPayload($pluginId, $data));
        } catch (\Exception $e) {
            Log::error('payment notify exception: ' . $e->getMessage(), array(
                'channel' => 'pay',
                'plugin' => $pluginId,
                'exception' => get_class($e),
            ));
            return $this->respond('fail');
        } catch (\Throwable $e) {
            Log::error('payment notify error: ' . $e->getMessage(), array(
                'channel' => 'pay',
                'plugin' => $pluginId,
                'exception' => get_class($e),
            ));
            return $this->respond('fail');
        }

        return $this->respond($result);
    }

    /**
     * 解析支付 Provider。
     *
     * @param string $pluginId
     * @return mixed
     */
    private function provider($pluginId)
    {
        $registry = Module::make('plugin.registry_aggregator');
        if (!is_object($registry) || !method_exists($registry, 'payment')) {
            return null;
        }

        $payment = $registry->payment();
        if (!is_object($payment) || !method_exists($payment, 'provider')) {
            return null;
        }

        return $payment->provider($pluginId);
    }

    /**
     * 输出网关原始响应：XML 用 application/xml，其余按纯文本返回。
     *
     * @param string $body
     * @return Response
     */
    private function respond($body)
    {
        $body = (string) $body;
        $trimmed = ltrim($body);
        $isXml = strncmp($trimmed, '<?xml', 5) === 0 || strncmp($trimmed, '<xml', 4) === 0;

        return new Response($body, 200, array(
            'Content-Type' => $isXml ? 'application/xml; charset=utf-8' : 'text/plain; charset=utf-8',
        ));
    }
}
