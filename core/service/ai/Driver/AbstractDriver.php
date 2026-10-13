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

namespace Dou\Core\Service\Ai\Driver;

use Dou\Core\Web\Http\Client;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 驱动基类：持有 resolveConfig 产物，供子类读取。
 */
abstract class AbstractDriver implements DriverInterface
{
    /** @var array */
    protected $config;

    /**
     * @param array $config resolveConfig 产物
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * {@inheritDoc}
     */
    public function extractUsage(array $decoded)
    {
        if (!isset($decoded['usage']) || !is_array($decoded['usage'])) {
            return null;
        }

        $usage = $decoded['usage'];
        $promptTokens = isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : 0;
        $completionTokens = isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : 0;

        return array(
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => isset($usage['total_tokens'])
                ? (int) $usage['total_tokens']
                : ($promptTokens + $completionTokens),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function supportsStream()
    {
        return true;
    }

    /**
     * 发起 embedding HTTP 请求并解码（请求体由子类按供应商协议组装）。
     *
     * 错误消息提取兼容两套口径：OpenAI 的 error.message 与 DashScope 原生的 message。
     *
     * @param string $url 完整端点地址
     * @param array $body 请求体
     * @return array {ok: bool, decoded: array, error: string}
     */
    protected function requestEmbeddingResponse($url, array $body)
    {
        $result = Client::request('POST', $url, json_encode($body), $this->buildHeaders($this->config), array(
            'timeout' => 30,
            'return_meta' => true,
        ));

        if (!is_array($result)) {
            return array('ok' => false, 'decoded' => array(), 'error' => lang('ai_http_request_failed'));
        }

        $httpCode = isset($result['http_code']) ? (int) $result['http_code'] : 0;
        $responseBody = isset($result['body']) ? $result['body'] : '';
        if ($httpCode !== 200) {
            $errorData = json_decode($responseBody, true);
            $errorMsg = '';
            if (is_array($errorData)) {
                if (isset($errorData['error']['message']) && $errorData['error']['message'] !== '') {
                    // OpenAI 兼容口径
                    $errorMsg = (string) $errorData['error']['message'];
                } elseif (isset($errorData['message']) && $errorData['message'] !== '') {
                    // DashScope 原生口径
                    $errorMsg = (string) $errorData['message'];
                }
            }
            if ($errorMsg === '') {
                $errorMsg = sprintf(lang('ai_http_error_code'), $httpCode);
            }

            return array('ok' => false, 'decoded' => array(), 'error' => $errorMsg);
        }

        $decoded = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return array('ok' => false, 'decoded' => array(), 'error' => lang('ai_api_response_invalid'));
        }

        return array('ok' => true, 'decoded' => $decoded, 'error' => '');
    }

    /**
     * 归一 embedding 结果：校验条数（必须与文本数一致，错位即灾难）与维度。
     *
     * @param array $ordered 已按 index / text_index 重排的向量数组（键为序号）
     * @param int $expectedCount 期望向量条数（= 入参文本数）
     * @return array {success: bool, vectors: array[], dim: int, error: string}
     */
    protected function buildEmbeddingResult(array $ordered, $expectedCount)
    {
        if (!$ordered || count($ordered) !== (int) $expectedCount) {
            return array('success' => false, 'vectors' => array(), 'dim' => 0, 'error' => lang('ai_embedding_response_invalid'));
        }

        $vectors = array_values($ordered);

        return array('success' => true, 'vectors' => $vectors, 'dim' => count($vectors[0]), 'error' => '');
    }
}
