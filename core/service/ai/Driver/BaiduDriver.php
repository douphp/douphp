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

use Dou\Core\Service\Ai\Auth\BaiduTokenAuth;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 百度文心驱动（同步 + access_token 换发）。
 *
 * 协议（官方文档口径）：
 * - 端点：{base}/rpc/2.0/ai_custom/v1/wenxinworkshop/chat/{model}?access_token=xxx
 * - 请求体：{messages: [{role, content}], temperature, max_output_tokens, ...}（OpenAI 风格字段）
 * - 响应体：{result: "回复内容", usage: {...}, error_code, error_msg}
 *
 * access_token 由 BaiduTokenAuth 换发并缓存到 ai_key.config（30 天）。
 * 流式暂不支持（extractStreamDelta 返回 null，前端 chat 请勿配百度模型走流式）。
 */
class BaiduDriver extends AbstractDriver
{
    /** @var BaiduTokenAuth */
    private $tokenAuth;

    /**
     * {@inheritDoc}
     */
    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->tokenAuth = new BaiduTokenAuth();
    }

    /**
     * {@inheritDoc}
     */
    public function buildRequestBody(array $config, array $messages, $stream = false, array $options = array())
    {
        $maxTokens = isset($options['max_tokens']) ? (int) $options['max_tokens'] : $config['max_tokens'];
        $temperature = isset($options['temperature']) ? (float) $options['temperature'] : $config['temperature'];

        $body = array(
            'messages' => $messages,
            'temperature' => $temperature,
        );
        if ($maxTokens > 0) {
            $body['max_output_tokens'] = $maxTokens;
        }

        return $body;
    }

    /**
     * {@inheritDoc}
     */
    public function buildHeaders(array $config)
    {
        return array(
            'Content-Type: application/json',
            'Accept: application/json',
        );
    }

    /**
     * {@inheritDoc}
     */
    public function extractContent(array $decoded)
    {
        return isset($decoded['result']) ? $decoded['result'] : null;
    }

    /**
     * 百度流式暂不支持。
     *
     * {@inheritDoc}
     */
    public function extractStreamDelta(array $chunk)
    {
        return null;
    }

    /**
     * 百度对话接口无 SSE 流式，明确拒绝以免流式路径拿到空响应。
     *
     * {@inheritDoc}
     */
    public function supportsStream()
    {
        return false;
    }

    /**
     * 计算真实请求 URL：对话端点 + 模型名 + access_token 查询参数。
     *
     * 供 AiGateway::chat() 的 URL 钩子调用；token 换发失败时返回空串，
     * 调用方据此给出「access_token 获取失败」错误。
     *
     * @param array $config
     * @return string 完整请求 URL；token 不可用时为空串
     */
    public function resolveRequestUrl(array $config)
    {
        $token = $this->tokenAuth->getToken($config, isset($config['key_id']) ? (int) $config['key_id'] : 0);
        if ($token === null || $token === '') {
            return '';
        }

        return $this->buildEndpointUrl($config) . '?access_token=' . rawurlencode($token);
    }

    /**
     * 对话端点 URL（不含 access_token 参数）。
     *
     * 基于 resolveConfig 拼好的 api_url（base_url + /chat 预设路径）追加模型名。
     *
     * @param array $config
     * @return string
     */
    protected function buildEndpointUrl(array $config)
    {
        return rtrim($config['api_url'], '/') . '/' . rawurlencode($config['model_code']);
    }
}
