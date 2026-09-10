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
 */
class QwenDriver extends AbstractDriver
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
}
