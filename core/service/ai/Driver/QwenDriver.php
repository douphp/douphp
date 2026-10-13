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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 通义千问 DashScope 格式驱动（独立，与 OpenAI 协议互不包含）。
 *
 * 请求体 `{model, input: {messages}, parameters: {...}}`，请求头追加
 * `X-DashScope-SSE`，响应提取走 `output.choices[...]` 路径。
 * 向量生成走 DashScope 原生 /services/embeddings/text-embedding/text-embedding（阿里云首选通道）。
 */
class QwenDriver extends AbstractDriver implements EmbeddingInterface
{
    /**
     * {@inheritDoc}
     */
    public function buildRequestBody(array $config, array $messages, $stream = false, array $options = array())
    {
        $maxTokens = isset($options['max_tokens']) ? (int) $options['max_tokens'] : $config['max_tokens'];
        $temperature = isset($options['temperature']) ? (float) $options['temperature'] : $config['temperature'];

        return array(
            'model' => $config['model_code'],
            'input' => array('messages' => $messages),
            'parameters' => array(
                'max_tokens' => $maxTokens,
                'temperature' => $temperature,
                'incremental_output' => (bool) $stream,
                'result_format' => 'message',
            ),
        );
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
            'X-DashScope-SSE: enable',
        );
    }

    /**
     * {@inheritDoc}
     */
    public function extractContent(array $decoded)
    {
        return isset($decoded['output']['choices'][0]['message']['content'])
            ? $decoded['output']['choices'][0]['message']['content']
            : null;
    }

    /**
     * {@inheritDoc}
     */
    public function extractStreamDelta(array $chunk)
    {
        return isset($chunk['output']['choices'][0]['delta']['content'])
            ? $chunk['output']['choices'][0]['delta']['content']
            : null;
    }

    /**
     * {@inheritDoc}
     */
    public function extractUsage(array $decoded)
    {
        if (!isset($decoded['usage']) || !is_array($decoded['usage'])) {
            return null;
        }

        // DashScope 原生口径为 input_tokens/output_tokens，兼容模式为 prompt_tokens/completion_tokens
        $usage = $decoded['usage'];
        $promptTokens = isset($usage['prompt_tokens'])
            ? (int) $usage['prompt_tokens']
            : (isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : 0);
        $completionTokens = isset($usage['completion_tokens'])
            ? (int) $usage['completion_tokens']
            : (isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : 0);

        return array(
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => isset($usage['total_tokens'])
                ? (int) $usage['total_tokens']
                : ($promptTokens + $completionTokens),
        );
    }

    /**
     * 批量向量生成：POST {base_url}/services/embeddings/text-embedding/text-embedding。
     *
     * DashScope 原生协议：input.texts 传文本数组，text_type=document（入库口径）；
     * 响应 output.embeddings[].embedding 按 text_index 重排（供应商不保证返回顺序）；
     * 原生接口单次最多 10 条，调用方按 embed_batch_size 分批（默认值与之一致）。
     */
    public function embedTexts(array $config, array $texts)
    {
        $texts = array_values($texts);
        if (!$texts) {
            return array('success' => false, 'vectors' => array(), 'dim' => 0, 'error' => lang('ai_embedding_texts_empty'));
        }

        $body = array(
            'model' => $config['model_code'],
            'input' => array('texts' => $texts),
            'parameters' => array('text_type' => 'document'),
        );
        $url = rtrim($config['base_url'], '/') . '/services/embeddings/text-embedding/text-embedding';

        $response = $this->requestEmbeddingResponse($url, $body);
        if (!$response['ok']) {
            return array('success' => false, 'vectors' => array(), 'dim' => 0, 'error' => $response['error']);
        }
        $decoded = $response['decoded'];

        $ordered = array();
        if (isset($decoded['output']['embeddings']) && is_array($decoded['output']['embeddings'])) {
            $fallbackIndex = 0;
            foreach ($decoded['output']['embeddings'] as $item) {
                if (!is_array($item) || empty($item['embedding']) || !is_array($item['embedding'])) {
                    continue;
                }
                $index = isset($item['text_index']) ? (int) $item['text_index'] : $fallbackIndex;
                $ordered[$index] = array_values($item['embedding']);
                $fallbackIndex++;
            }
        }
        ksort($ordered);

        return $this->buildEmbeddingResult($ordered, count($texts));
    }
}
