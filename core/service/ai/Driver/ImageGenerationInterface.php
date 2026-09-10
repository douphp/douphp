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
 * 同步文生图驱动契约：一次请求直接返回图片结果。
 *
 * OpenAI images 风格供应商实现本接口；百炼 qwen-image-2.x 等仅支持同步的模型
 * 也实现本接口（同驱动仍可同时实现 AsyncDriverInterface，由网关按 async_only 分流）。
 */
interface ImageGenerationInterface
{
    /**
     * 同步生成图片。
     *
     * @param array $config resolveConfig 产物
     * @param array $params prompt（必填）/ n / size（驱动按供应商支持情况取舍）
     * @return array {success: bool, urls: string[], error: string}
     *                                                              urls 为图片地址（http URL 或 data: URI）列表
     */
    public function generateImage(array $config, array $params);
}
