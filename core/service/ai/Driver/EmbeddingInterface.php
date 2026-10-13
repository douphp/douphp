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
 * Release Date: 2026-10-11
 */

namespace Dou\Core\Service\Ai\Driver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 批量文本向量（embedding）驱动契约。
 *
 * 与 {@see ImageGenerationInterface} 同构：驱动自拼供应商端点，网关按接口能力分流；
 * OpenAI 兼容模式（百炼兼容端点 / 火山方舟 / DeepSeek / OpenRouter 等）与
 * DashScope 原生模式（QwenDriver）分别实现，未实现驱动由 AiGateway::embed() 拒绝。
 */
interface EmbeddingInterface
{
    /**
     * 批量生成文段向量。
     *
     * @param array $config resolveConfig 产物（base_url / api_key / model_code 等）
     * @param array $texts 文本数组；**返回向量的顺序必须与入参文本顺序一致**
     *                     （供应商不保证返回顺序，驱动须按 index / text_index 重排）
     * @return array {success: bool, vectors: array[], dim: int, error: string}
     *               vectors 为浮点数组的数组；dim 为单条向量维度（失败为 0）
     */
    public function embedTexts(array $config, array $texts);
}
