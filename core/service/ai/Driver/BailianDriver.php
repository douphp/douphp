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
 * 阿里百炼（DashScope）驱动：图像 / 视频生成。
 *
 * 协议（官方文档口径）：
 * - 万相 / qwen-image / qwen-image-plus（异步）：POST {base}/services/aigc/text2image/image-synthesis
 *   请求头 X-DashScope-Async: enable，请求体 {model, input: {prompt}, parameters}，响应 output.task_id
 * - qwen-image-2.x / 3.x / max（仅同步）：POST {base}/services/aigc/multimodal-generation/generation
 *   不带 Async 头，请求体 {model, input: {messages}, parameters}，响应 output.choices[].message.content[].image
 * - 提交视频：POST {base}/services/aigc/video-generation/video-synthesis
 * - 查询任务：GET {base}/tasks/{task_id}
 *
 * 注：provider 必须为独立 bailian 记录（base_url 如 https://dashscope.aliyuncs.com/api/v1），
 * 与兼容模式 aliyun 分开。
 */
class BailianDriver extends AbstractDriver implements AsyncDriverInterface, ImageGenerationInterface
{
    const ENDPOINT_IMAGE = '/services/aigc/text2image/image-synthesis';
    const ENDPOINT_IMAGE_EDIT = '/services/aigc/image2image/image-synthesis';
    const ENDPOINT_VIDEO = '/services/aigc/video-generation/video-synthesis';
    const ENDPOINT_MULTIMODAL = '/services/aigc/multimodal-generation/generation';

    /** @var int 结果 URL 为临时签名链接，按 24 小时保守提示过期 */
    const RESULT_TTL_SECONDS = 86400;

    /**
     * 异步驱动不参与同步/流式路径。
     *
     * {@inheritDoc}
     */
    public function buildRequestBody(array $config, array $messages, $stream = false, array $options = array())
    {
        return array();
    }

    /**
     * {@inheritDoc}
     */
    public function buildHeaders(array $config)
    {
        return $this->authHeaders($config);
    }

    /**
     * 异步驱动不参与同步路径。
     *
     * {@inheritDoc}
     */
    public function extractContent(array $decoded)
    {
        return null;
    }

    /**
     * 异步驱动不参与流式路径。
     *
     * {@inheritDoc}
     */
    public function extractStreamDelta(array $chunk)
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsStream()
    {
        return false;
    }

    /**
     * 同步文生图 / 图生图：qwen-image-2.x / 3.x / max 走 multimodal-generation，一次请求返回图片 URL。
     * params.image_refs 有值时作为参考图写入 user content（图在前、文在后）。
     * 其余百炼图像模型返回 async_only，由网关改走 submitTask。
     *
     * {@inheritDoc}
     */
    public function generateImage(array $config, array $params)
    {
        $modelCode = isset($config['model_code']) ? (string) $config['model_code'] : '';
        if (!$this->isSyncMultimodalModel($modelCode)) {
            return array('success' => false, 'urls' => array(), 'error' => '', 'async_only' => true);
        }

        $prompt = isset($params['prompt']) ? trim((string) $params['prompt']) : '';
        if ($prompt === '') {
            return array('success' => false, 'urls' => array(), 'error' => lang('ai_image_prompt_empty'));
        }

        $endpoint = self::ENDPOINT_MULTIMODAL;
        $url = rtrim($config['base_url'], '/') . $endpoint;
        $parameters = $this->imageParameters($config, $params);
        $body = array(
            'model' => $modelCode,
            'input' => array(
                'messages' => array(
                    array(
                        'role' => 'user',
                        'content' => $this->multimodalContent($prompt, $params),
                    ),
                ),
            ),
            'parameters' => $parameters,
        );

        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $result = Client::request(
            'POST',
            $url,
            json_encode($body, JSON_UNESCAPED_UNICODE),
            $this->authHeaders($config),
            array('timeout' => 120, 'return_meta' => true)
        );

        if (!is_array($result)) {
            return array('success' => false, 'urls' => array(), 'error' => lang('ai_http_request_failed'));
        }

        $httpCode = isset($result['http_code']) ? (int) $result['http_code'] : 0;
        $decoded = json_decode(isset($result['body']) ? $result['body'] : '', true);
        if (!is_array($decoded)) {
            $decoded = array();
        }

        $urls = $this->extractMultimodalImageUrls($decoded);

        if ($httpCode !== 200) {
            return array(
                'success' => false,
                'urls' => array(),
                'error' => $this->apiError($decoded, $httpCode),
            );
        }
        if (!$urls) {
            return array('success' => false, 'urls' => array(), 'error' => lang('ai_api_response_invalid'));
        }

        return array('success' => true, 'urls' => $urls, 'error' => '');
    }

    /**
     * {@inheritDoc}
     */
    public function submitTask(array $config, array $params)
    {
        $modelCode = isset($config['model_code']) ? (string) $config['model_code'] : '';
        if ($this->isSyncMultimodalModel($modelCode)) {
            $gen = $this->generateImage($config, $params);
            if (!empty($gen['success'])) {
                return array(
                    'provider_task_id' => '',
                    'status' => 'succeeded',
                    'result' => array('urls' => isset($gen['urls']) ? $gen['urls'] : array()),
                    'error' => '',
                    'sync' => true,
                );
            }

            return array(
                'provider_task_id' => '',
                'status' => '',
                'error' => isset($gen['error']) && $gen['error'] !== '' ? $gen['error'] : lang('ai_generate_failed'),
            );
        }

        // 参考图（图生图）：走 image2image 端点，input.url 携带首张参考图（data: URI 或公网 URL）；
        // 调用方仅在模型支持图生图时传入
        $refs = array();
        if (!empty($params['image_refs']) && is_array($params['image_refs'])) {
            foreach ($params['image_refs'] as $ref) {
                $ref = trim((string) $ref);
                if ($ref !== '') {
                    $refs[] = $ref;
                }
            }
        }

        if ($refs && $config['model_type'] !== 'video') {
            $endpoint = self::ENDPOINT_IMAGE_EDIT;
            $input = array(
                'url' => $refs[0],
                'prompt' => isset($params['prompt']) ? (string) $params['prompt'] : '',
            );
        } else {
            $endpoint = ($config['model_type'] === 'video') ? self::ENDPOINT_VIDEO : self::ENDPOINT_IMAGE;
            $input = array('prompt' => isset($params['prompt']) ? (string) $params['prompt'] : '');
        }

        $url = rtrim($config['base_url'], '/') . $endpoint;

        $parameters = $this->imageParameters($config, $params);
        foreach (array('resolution', 'duration') as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $parameters[$key] = $params[$key];
            }
        }

        $body = array(
            'model' => $config['model_code'],
            'input' => $input,
            'parameters' => $parameters,
        );

        $result = Client::request(
            'POST',
            $url,
            json_encode($body, JSON_UNESCAPED_UNICODE),
            array_merge($this->authHeaders($config), array('X-DashScope-Async: enable')),
            array('timeout' => 60, 'return_meta' => true)
        );

        if (!is_array($result)) {
            return array('provider_task_id' => '', 'status' => '', 'error' => lang('ai_http_request_failed'));
        }

        $httpCode = isset($result['http_code']) ? (int) $result['http_code'] : 0;
        $decoded = json_decode(isset($result['body']) ? $result['body'] : '', true);
        if (!is_array($decoded)) {
            $decoded = array();
        }

        return $this->parseSubmitResponse($decoded, $httpCode);
    }

    /**
     * {@inheritDoc}
     */
    public function pollTask(array $config, $providerTaskId)
    {
        $url = rtrim($config['base_url'], '/') . '/tasks/' . rawurlencode((string) $providerTaskId);

        $result = Client::request(
            'GET',
            $url,
            array(),
            $this->authHeaders($config),
            array('timeout' => 30, 'return_meta' => true)
        );

        if (!is_array($result)) {
            // 查询失败保持 running，下次轮询再试
            return array('status' => 'running', 'result' => null, 'error' => '');
        }

        $httpCode = isset($result['http_code']) ? (int) $result['http_code'] : 0;
        $decoded = json_decode(isset($result['body']) ? $result['body'] : '', true);
        if (!is_array($decoded)) {
            $decoded = array();
        }

        // 4xx（限流除外）为不可恢复错误：密钥失效 / 任务不存在等，直接判失败，避免白等到超时
        if ($httpCode >= 400 && $httpCode < 500 && $httpCode !== 429) {
            return array(
                'status' => 'failed',
                'result' => null,
                'error' => sprintf(lang('ai_task_query_failed'), $this->apiError($decoded, $httpCode)),
            );
        }

        if (!isset($decoded['output'])) {
            return array('status' => 'running', 'result' => null, 'error' => '');
        }

        return $this->parsePollResponse($decoded);
    }

    /**
     * 解析任务提交响应。
     *
     * @param array $decoded 提交响应 JSON
     * @param int $httpCode
     * @return array {provider_task_id, status, raw, error}
     */
    protected function parseSubmitResponse(array $decoded, $httpCode)
    {
        if ($httpCode !== 200) {
            return array(
                'provider_task_id' => '',
                'status' => '',
                'error' => sprintf(lang('ai_task_submit_failed_detail'), $this->apiError($decoded, $httpCode)),
            );
        }

        $taskId = isset($decoded['output']['task_id']) ? (string) $decoded['output']['task_id'] : '';

        return array(
            'provider_task_id' => $taskId,
            'status' => $taskId === '' ? '' : 'running',
            'raw' => $decoded,
            'error' => '',
        );
    }

    /**
     * 解析任务状态查询响应。
     *
     * @param array $decoded 查询响应 JSON（含 output.task_status）
     * @return array {status, result, expires_at, error}
     */
    protected function parsePollResponse(array $decoded)
    {
        $taskStatus = isset($decoded['output']['task_status'])
            ? strtoupper((string) $decoded['output']['task_status'])
            : '';

        if ($taskStatus === 'SUCCEEDED') {
            $urls = array();
            $results = isset($decoded['output']['results']) && is_array($decoded['output']['results'])
                ? $decoded['output']['results']
                : array();
            foreach ($results as $item) {
                if (isset($item['url']) && $item['url'] !== '') {
                    $urls[] = (string) $item['url'];
                } elseif (isset($item['video_url']) && $item['video_url'] !== '') {
                    $urls[] = (string) $item['video_url'];
                }
            }

            return array(
                'status' => 'succeeded',
                'result' => array('urls' => $urls, 'raw' => $decoded),
                'expires_at' => date('Y-m-d H:i:s', time() + self::RESULT_TTL_SECONDS),
                'error' => '',
            );
        }

        if ($taskStatus === 'FAILED') {
            $error = isset($decoded['output']['message']) && $decoded['output']['message'] !== ''
                ? (string) $decoded['output']['message']
                : lang('ai_task_failed');

            return array('status' => 'failed', 'result' => null, 'error' => $error);
        }

        // PENDING / RUNNING / UNKNOWN：继续轮询
        return array('status' => 'running', 'result' => null, 'error' => '');
    }

    /**
     * qwen-image-2.x / 3.x / max 仅支持同步 multimodal-generation，禁止 X-DashScope-Async。
     *
     * @param string $modelCode
     * @return bool
     */
    private function isSyncMultimodalModel($modelCode)
    {
        $code = strtolower((string) $modelCode);
        if (strpos($code, 'qwen-image-2') === 0) {
            return true;
        }
        if (strpos($code, 'qwen-image-3') === 0) {
            return true;
        }
        if (strpos($code, 'qwen-image-max') === 0) {
            return true;
        }

        return false;
    }

    /**
     * 组装 multimodal-generation 的 user content：参考图在前、文本在后。
     *
     * @param string $prompt
     * @param array $params
     * @return array
     */
    private function multimodalContent($prompt, array $params)
    {
        $content = array();
        if (!empty($params['image_refs']) && is_array($params['image_refs'])) {
            foreach ($params['image_refs'] as $ref) {
                $ref = trim((string) $ref);
                if ($ref !== '') {
                    $content[] = array('image' => $ref);
                }
            }
        }
        $content[] = array('text' => $prompt);

        return $content;
    }

    /**
     * 组装图像 parameters（n / size），size 经 ImageSizeNormalizer，空串则丢弃。
     *
     * @param array $config
     * @param array $params
     * @return array
     */
    private function imageParameters(array $config, array $params)
    {
        $parameters = array('n' => 1);
        if (!empty($params['n'])) {
            $parameters['n'] = max(1, (int) $params['n']);
        }
        $size = '';
        if (isset($params['size']) && $params['size'] !== '') {
            $size = (string) $params['size'];
        } elseif (isset($params['width'], $params['height']) && $params['width'] !== '' && $params['height'] !== '') {
            $size = $params['width'] . '*' . $params['height'];
        }
        if ($size !== '') {
            $normalized = ImageSizeNormalizer::normalize(
                isset($config['model_code']) ? (string) $config['model_code'] : '',
                $size,
                isset($config['provider_code']) ? (string) $config['provider_code'] : ''
            );
            if ($normalized !== '') {
                $parameters['size'] = $normalized;
            }
        }

        return $parameters;
    }

    /**
     * 从 multimodal-generation 同步响应提取图片 URL。
     *
     * @param array $decoded
     * @return array
     */
    private function extractMultimodalImageUrls(array $decoded)
    {
        $urls = array();
        $contents = isset($decoded['output']['choices'][0]['message']['content'])
            ? $decoded['output']['choices'][0]['message']['content']
            : array();
        if (!is_array($contents)) {
            return $urls;
        }
        foreach ($contents as $item) {
            if (is_array($item) && isset($item['image']) && $item['image'] !== '') {
                $urls[] = (string) $item['image'];
            }
        }

        return $urls;
    }

    /**
     * Bearer 认证请求头。
     *
     * @param array $config
     * @return array
     */
    private function authHeaders(array $config)
    {
        return array(
            'Authorization: Bearer ' . $config['api_key'],
            'Content-Type: application/json',
            'Accept: application/json',
        );
    }

    /**
     * 从错误响应提取可读信息。
     *
     * @param array $decoded
     * @param int $httpCode
     * @return string
     */
    private function apiError(array $decoded, $httpCode)
    {
        if (isset($decoded['message']) && $decoded['message'] !== '') {
            return (string) $decoded['message'];
        }
        if (isset($decoded['code']) && $decoded['code'] !== '') {
            return sprintf(lang('ai_error_code'), $decoded['code']);
        }

        return 'HTTP ' . $httpCode;
    }
}
