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
}
