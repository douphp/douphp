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
 * Release Date: 2026-11-11
 */

namespace Dou\Core\Service\Aigc;

use Dou\Core\Facade\DB;
use Dou\Core\Service\Ai\AiGateway;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * aigc 用量入账：aigc_usage_log 明细（文本对话 + 任务式生成）。
 */
class UsageRecorder extends BaseService
{
    /** @var AiGateway */
    private $gateway;

    /**
     * @param AiGateway $gateway
     */
    public function __construct(AiGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * 记一笔调用账（成功或失败）。
     *
     * @param array $config {@see AiGateway::resolveConfig()} 产物
     * @param array $usage {prompt_tokens, completion_tokens, total_tokens, duration, error}
     * @param int $userId
     * @param int $appId
     * @param int $sessionId
     * @param string $ip
     * @param string $userAgent
     * @param int $taskId 生成任务 ID（文本对话为 0）
     * @return void
     */
    public function record(array $config, array $usage, $userId, $appId, $sessionId, $ip, $userAgent, $taskId = 0)
    {
        $promptTokens = isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : 0;
        $completionTokens = isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : 0;
        $totalTokens = isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : ($promptTokens + $completionTokens);
        $duration = isset($usage['duration']) ? (int) $usage['duration'] : 0;
        $error = isset($usage['error']) ? trim((string) $usage['error']) : '';
        $hasError = $error !== '' ? 1 : 0;

        DB::table('aigc_usage_log')->data(array(
            'user_id' => (int) $userId,
            'app_id' => (int) $appId,
            'session_id' => (int) $sessionId,
            'task_id' => (int) $taskId,
            'model_id' => isset($config['model_id']) ? (int) $config['model_id'] : 0,
            'provider_id' => isset($config['provider_id']) ? (int) $config['provider_id'] : 0,
            'key_id' => isset($config['key_id']) ? (int) $config['key_id'] : 0,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'duration' => $duration,
            'has_error' => $hasError,
            'error_message' => $hasError ? $error : null,
            'endpoint' => isset($config['api_url']) ? (string) $config['api_url'] : null,
            'ip' => (string) $ip,
            'user_agent' => function_exists('mb_substr') ? mb_substr((string) $userAgent, 0, 250, 'UTF-8') : substr((string) $userAgent, 0, 250),
            'created_at' => date('Y-m-d H:i:s'),
        ))->insert();

        if (!empty($config['key_id'])) {
            $this->gateway->touchKey((int) $config['key_id']);
        }
    }
}
