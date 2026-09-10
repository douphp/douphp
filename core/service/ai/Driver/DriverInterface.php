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
 * 供应商协议驱动契约。
 *
 * 每个驱动封装一个供应商（或一类协议）的请求体/请求头组装与响应提取，
 * 由 DriverFactory 按 provider.code（或 stream_format 兜底）路由。
 */
interface DriverInterface
{
    /**
     * 组装请求体。
     *
     * @param array $config resolveConfig 产物
     * @param array $messages 对话消息
     * @param bool $stream 是否流式
     * @param array $options 可选 temperature / max_tokens / response_format
     * @return array
     */
    public function buildRequestBody(array $config, array $messages, $stream = false, array $options = array());

    /**
     * 组装请求头（含认证）。
     *
     * @param array $config
     * @return array
     */
    public function buildHeaders(array $config);

    /**
     * 非流式：从响应 JSON 解码出正文。
     *
     * @param array $decoded 已 json_decode 的响应
     * @return string|null 无法提取返回 null
     */
    public function extractContent(array $decoded);

    /**
     * 流式：从单个 chunk JSON 解码出增量内容。
     *
     * @param array $chunk 已 json_decode 的单个 SSE chunk
     * @return string|null 无增量返回 null
     */
    public function extractStreamDelta(array $chunk);

    /**
     * 从响应中提取并归一化 token 用量（供应商字段口径差异在此抹平）。
     *
     * @param array $decoded 已 json_decode 的响应（或单个 SSE chunk）
     * @return array|null {prompt_tokens, completion_tokens, total_tokens}；无用量信息返回 null
     */
    public function extractUsage(array $decoded);

    /**
     * 是否支持流式（SSE）输出。
     *
     * @return bool
     */
    public function supportsStream();
}
