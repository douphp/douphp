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

use Dou\Core\Service\Ai\ImageSizeNormalizer;
use Dou\Core\Web\Http\Client;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * OpenAI 标准协议驱动（默认，覆盖类 A/B 所有 OpenAI 兼容供应商）。
 *
 * 请求体 `{model, messages, ...}`，Bearer 认证，`choices[].message.content` 提取。
 * 图像生成走 `/images/generations` 同步端点（豆包 Speedream / 智谱 CogView / OpenAI 兼容）。
 */
class OpenAiDriver extends AbstractDriver implements ImageGenerationInterface
{
    /**
     * {@inheritDoc}
     */
    public function buildRequestBody(array $config, array $messages, $stream = false, array $options = array())
    {
        $maxTokens = isset($options['max_tokens']) ? (int) $options['max_tokens'] : $config['max_tokens'];
        $temperature = isset($options['temperature']) ? (float) $options['temperature'] : $config['temperature'];

        $body = array(
            'model' => $config['model_code'],
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'stream' => (bool) $stream,
        );
        if ($stream) {
            // 流式响应在最后一个 chunk 携带 usage（OpenAI 兼容口径）
            $body['stream_options'] = array('include_usage' => true);
        }
        if (isset($options['response_format']) && is_array($options['response_format'])) {
            $body['response_format'] = $options['response_format'];
        }

        return $body;
    }

    /**
     * {@inheritDoc}
     */
    public function buildHeaders(array $config)
    {
        return array(
            'Authorization: Bearer ' . $config['api_key'],
            'Content-Type: application/json',
            'Accept: application/json',
        );
    }

    /**
     * {@inheritDoc}
     */
    public function extractContent(array $decoded)
    {
        return isset($decoded['choices'][0]['message']['content'])
            ? $decoded['choices'][0]['message']['content']
            : null;
    }

    /**
     * {@inheritDoc}
     */
    public function extractStreamDelta(array $chunk)
    {
        return isset($chunk['choices'][0]['delta']['content'])
            ? $chunk['choices'][0]['delta']['content']
            : null;
    }

    /**
     * 同步文生图：POST {base_url}/images/generations（OpenAI images 协议）。
     *
     * 豆包 Speedream、智谱 CogView、OpenAI DALL·E 等共用本端点；
     * 返回 data[].url 或 data[].b64_json（后者转 data: URI 统一承载）。
     */
    public function generateImage(array $config, array $params)
    {
        $prompt = isset($params['prompt']) ? trim((string) $params['prompt']) : '';
        if ($prompt === '') {
            return array('success' => false, 'urls' => array(), 'error' => lang('ai_image_prompt_empty'));
        }

        $body = array(
            'model' => $config['model_code'],
            'prompt' => $prompt,
        );
        // 参考图（图生图）：Ark Seedream / 硅基流动等 OpenAI 兼容端点以 image 数组接收，
        // 元素为 data: URI 或公网 URL；调用方仅在模型支持图生图时传入
        if (!empty($params['image_refs']) && is_array($params['image_refs'])) {
            $refs = array();
            foreach ($params['image_refs'] as $ref) {
                $ref = trim((string) $ref);
                if ($ref !== '') {
                    $refs[] = $ref;
                }
            }
            if ($refs) {
                $body['image'] = array_slice($refs, 0, 4);
            }
        }
        if (!empty($params['n'])) {
            $body['n'] = max(1, min(4, (int) $params['n']));
        }
        if (!empty($params['size'])) {
            // 尺寸归一化：按模型策略适配格式与合法值；未登记模型返回空串（丢弃 size，用上游默认，绝不因尺寸导致失败）
            $size = ImageSizeNormalizer::normalize($config['model_code'], (string) $params['size'], isset($config['provider_code']) ? (string) $config['provider_code'] : '');
            if ($size !== '') {
                $body['size'] = $size;
            }
        }

        $url = rtrim($config['base_url'], '/') . '/images/generations';
        $result = Client::request('POST', $url, json_encode($body), $this->buildHeaders($config), array(
            'timeout' => 120,
            'return_meta' => true,
        ));

        if (!is_array($result)) {
            return array('success' => false, 'urls' => array(), 'error' => lang('ai_http_request_failed'));
        }

        $httpCode = isset($result['http_code']) ? (int) $result['http_code'] : 0;
        $responseBody = isset($result['body']) ? $result['body'] : '';
        if ($httpCode !== 200) {
            $errorData = json_decode($responseBody, true);
            $errorMsg = isset($errorData['error']['message']) && $errorData['error']['message'] !== ''
                ? $errorData['error']['message']
                : sprintf(lang('ai_http_error_code'), $httpCode);

            return array('success' => false, 'urls' => array(), 'error' => $errorMsg);
        }

        $decoded = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return array('success' => false, 'urls' => array(), 'error' => lang('ai_api_response_invalid'));
        }

        $urls = array();
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            foreach ($decoded['data'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (!empty($item['url'])) {
                    $urls[] = (string) $item['url'];
                } elseif (!empty($item['b64_json'])) {
                    $urls[] = 'data:image/png;base64,' . $item['b64_json'];
                }
            }
        }

        if (!$urls) {
            $errorMsg = isset($decoded['error']['message']) && $decoded['error']['message'] !== ''
                ? $decoded['error']['message']
                : lang('ai_api_response_invalid');

            return array('success' => false, 'urls' => array(), 'error' => $errorMsg);
        }

        return array('success' => true, 'urls' => $urls, 'error' => '');
    }
}
