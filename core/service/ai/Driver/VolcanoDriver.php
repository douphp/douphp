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
 * 火山方舟（豆包）视频生成异步任务驱动。
 *
 * 协议（官方文档口径）：
 * - 提交：POST {base}/api/v3/contents/generations/tasks
 *   请求体 {model, content: [{type: "text", text: prompt}]}，响应 {id, status: "queued"}
 * - 查询：GET {base}/api/v3/contents/generations/tasks/{id}
 *   响应 status：queued/running/succeeded/failed/cancelled，
 *   成功时 content.video_url 携带视频地址
 *
 * 提交/查询均为普通 HTTPS 短请求，虚拟主机可用。
 */
class VolcanoDriver extends AbstractDriver implements AsyncDriverInterface
{
    const ENDPOINT_VIDEO = '/api/v3/contents/generations/tasks';

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
     * {@inheritDoc}
     */
    public function submitTask(array $config, array $params)
    {
        $url = rtrim($config['base_url'], '/') . self::ENDPOINT_VIDEO;

        $prompt = isset($params['prompt']) ? (string) $params['prompt'] : '';
        $content = array(array('type' => 'text', 'text' => $prompt));
        foreach (array('resolution', 'duration') as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $content[] = array('type' => 'text', 'text' => $key . ': ' . $params[$key]);
            }
        }

        $body = array(
            'model' => $config['model_code'],
            'content' => $content,
        );

        $result = Client::request(
            'POST',
            $url,
            json_encode($body, JSON_UNESCAPED_UNICODE),
            $this->authHeaders($config),
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
        $url = rtrim($config['base_url'], '/') . self::ENDPOINT_VIDEO . '/' . rawurlencode((string) $providerTaskId);

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

        $taskId = isset($decoded['id']) ? (string) $decoded['id'] : '';

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
     * @param array $decoded 查询响应 JSON（含 status/content）
     * @return array {status, result, expires_at, error}
     */
    protected function parsePollResponse(array $decoded)
    {
        $status = isset($decoded['status']) ? strtolower((string) $decoded['status']) : '';

        if ($status === 'succeeded') {
            $urls = array();
            if (isset($decoded['content']['video_url']) && $decoded['content']['video_url'] !== '') {
                $urls[] = (string) $decoded['content']['video_url'];
            } elseif (isset($decoded['content']['url']) && $decoded['content']['url'] !== '') {
                $urls[] = (string) $decoded['content']['url'];
            }

            return array(
                'status' => 'succeeded',
                'result' => array('urls' => $urls, 'raw' => $decoded),
                'expires_at' => date('Y-m-d H:i:s', time() + self::RESULT_TTL_SECONDS),
                'error' => '',
            );
        }

        if ($status === 'failed' || $status === 'cancelled') {
            $error = lang('ai_task_failed');
            if (isset($decoded['error']) && is_array($decoded['error']) && isset($decoded['error']['message'])) {
                $error = (string) $decoded['error']['message'];
            } elseif (isset($decoded['error']) && is_string($decoded['error']) && $decoded['error'] !== '') {
                $error = (string) $decoded['error'];
            }

            return array('status' => 'failed', 'result' => null, 'error' => $error);
        }

        // queued / running：继续轮询
        return array('status' => 'running', 'result' => null, 'error' => '');
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
        if (isset($decoded['error']['message']) && $decoded['error']['message'] !== '') {
            return (string) $decoded['error']['message'];
        }
        if (isset($decoded['message']) && $decoded['message'] !== '') {
            return (string) $decoded['message'];
        }

        return 'HTTP ' . $httpCode;
    }
}
