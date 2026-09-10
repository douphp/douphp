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

namespace Dou\Core\Service\Ai;

use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\Ai\Factory\DriverFactory;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * AI SSE 流式输出器：把上游模型 API 的流式响应逐 chunk 转发给浏览器，
 * 结束后把「完整回复 + token 用量 + 耗时」交还调用方落库。本类自身不写库。
 */
class AiStreamer extends BaseService
{
    /** @var AiGateway */
    private $gateway;

    /**
     * @param AiGateway $gateway
     */
    public function __construct(AiGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * 输出 SSE 响应头并清空输出缓冲（开始转发前调用一次）。
     *
     * @return void
     */
    public function prepareResponse()
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        while (ob_get_level()) {
            ob_end_clean();
        }

        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
    }

    /**
     * 转发流式对话：上游每个增量 content 以 `data: {"content": ...}` 推给客户端，
     * 结束推 `data: [DONE]`；错误推 `data: {"error": ...}`。
     *
     * @param array $config {@see AiGateway::resolveConfig()} 产物
     * @param array $messages 对话消息
     * @param array $options 可选 temperature / max_tokens / timeout
     * @return array {content, prompt_tokens, completion_tokens, total_tokens, duration, error}
     */
    public function stream(array $config, array $messages, array $options = array())
    {
        $driver = DriverFactory::make($config);

        // 流式能力守卫：百度等同步协议驱动无 SSE，明确拒绝而非拿到空响应
        if (!$driver->supportsStream()) {
            $error = lang('ai_stream_unsupported');
            $this->emit(array('error' => $error));
            Log::error('AI stream unsupported', array(
                'channel' => 'ai',
                'error' => $error,
                'model_id' => (int) $config['model_id'],
            ));

            return array(
                'content' => '',
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'duration' => 0,
                'error' => $error,
            );
        }

        // URL 钩子：与 AiGateway::chat() 一致，驱动可自定义请求地址（如拼 access_token）
        $url = method_exists($driver, 'resolveRequestUrl')
            ? $driver->resolveRequestUrl($config)
            : $config['api_url'];
        if ($url === '') {
            $error = lang('ai_api_credential_failed');
            $this->emit(array('error' => $error));
            Log::error('AI stream url resolve failed', array(
                'channel' => 'ai',
                'error' => $error,
                'model_id' => (int) $config['model_id'],
            ));

            return array(
                'content' => '',
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'duration' => 0,
                'error' => $error,
            );
        }

        $body = $this->gateway->buildRequestBody($config, $messages, true, $options);
        $headers = $this->gateway->buildHeaders($config);
        $timeout = isset($options['timeout']) ? (int) $options['timeout'] : 120;

        ignore_user_abort(false);

        $startTime = microtime(true);
        $promptTokens = 0;
        $completionTokens = 0;
        $totalTokens = 0;
        $aiResponse = '';
        $streamError = '';
        $sseBuffer = '';

        $httpCode = 0;
        $errorBuffer = '';
        $headerReceived = false;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$httpCode, &$headerReceived) {
            if (connection_aborted()) {
                return 0;
            }
            if (preg_match('/^HTTP\/[\d.]+ (\d+)/', $header, $matches)) {
                $httpCode = (int) $matches[1];
                $headerReceived = true;
            }

            return strlen($header);
        });

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (
            &$httpCode,
            &$errorBuffer,
            &$headerReceived,
            &$promptTokens,
            &$completionTokens,
            &$totalTokens,
            &$aiResponse,
            &$streamError,
            &$sseBuffer,
            $driver
        ) {
            if (connection_aborted()) {
                return 0;
            }

            if ($headerReceived && $httpCode !== 200) {
                $errorBuffer .= $data;

                return strlen($data);
            }

            // SSE 帧可能被 TCP 包切断，按换行缓冲重组后再解析
            $sseBuffer .= $data;
            $lines = explode("\n", $sseBuffer);
            $sseBuffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line === 'data: [DONE]') {
                    continue;
                }
                if (strpos($line, 'data: ') !== 0) {
                    continue;
                }

                $chunk = json_decode(substr($line, 6), true);
                if (!is_array($chunk)) {
                    continue;
                }

                if (isset($chunk['error'])) {
                    $errorMsg = isset($chunk['error']['message']) ? $chunk['error']['message'] : lang('ai_api_unknown_error');
                    $streamError = sprintf(lang('ai_api_error'), $errorMsg);
                    $this->emit(array('error' => $streamError));

                    return 0;
                }

                $content = $driver->extractStreamDelta($chunk);

                if ($content !== null && $content !== '') {
                    $aiResponse .= $content;
                    $this->emit(array('content' => $content));
                }

                if (isset($chunk['usage'])) {
                    // 用量提取委托驱动（抹平供应商字段口径差异，如 DashScope 的 input/output_tokens）
                    $usage = $driver->extractUsage($chunk);
                    if (is_array($usage)) {
                        $promptTokens = (int) $usage['prompt_tokens'];
                        $completionTokens = (int) $usage['completion_tokens'];
                        $totalTokens = (int) $usage['total_tokens'];
                    }
                }
            }

            return strlen($data);
        });

        curl_exec($ch);
        $duration = (int) ((microtime(true) - $startTime) * 1000);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        if ($curlErrno && $streamError === '') {
            $streamError = sprintf(lang('ai_curl_error'), $curlErrno, $curlError);
            $this->emit(array('error' => $streamError));
        } elseif ($httpCode !== 200 && $httpCode !== 0 && $streamError === '') {
            $errorData = json_decode($errorBuffer, true);
            $errorMsg = isset($errorData['error']['message'])
                ? $errorData['error']['message']
                : sprintf(lang('ai_http_error_code'), $httpCode);
            if ($httpCode === 404) {
                $errorMsg .= lang('ai_api_base_url_hint');
            }
            $streamError = sprintf(lang('ai_api_request_failed'), $errorMsg);
            $this->emit(array('error' => $streamError));
        }

        if ($streamError !== '') {
            Log::error('AI stream failure', array(
                'channel' => 'ai',
                'error' => $streamError,
                'model_id' => (int) $config['model_id'],
            ));
        }

        if (!connection_aborted()) {
            echo "data: [DONE]\n\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        }

        return array(
            'content' => $aiResponse,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'duration' => $duration,
            'error' => $streamError,
        );
    }

    /**
     * 推送一帧 SSE 数据并刷出缓冲。
     *
     * @param array $payload
     * @return void
     */
    private function emit(array $payload)
    {
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
}
