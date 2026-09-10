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

namespace Dou\Admin\Service\Ai;

use Dou\Admin\Model\Ai\AiLog;
use Dou\Core\Facade\DB;
use Dou\Core\Service\Ai\AiGateway;
use Dou\Core\Service\BaseService;
use Dou\Core\Web\Http\ApiResponse;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 用量落账：单次调用明细（ai_log）。
 */
class UsageRecorder extends BaseService
{
    const CONTENT_RETENTION_DAYS = 30;

    const MAX_CONTENT_BYTES = 20000;

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
     * 记录一次 AI 调用（含失败）。
     *
     * @param array $result AiGateway::chat / chatJson 返回值
     * @param int $appId 应用 ID
     * @param int $adminId 管理员 ID
     * @param string $ip 管理员 IP
     * @param array $metadata 额外元数据（placement / module / count 等）
     * @param string|null $prompt 最终完整提示词（chat 为 [role] 分段全文，图像为单段 prompt）
     * @return void
     */
    public function record(array $result, $appId, $adminId, $ip, array $metadata = array(), $prompt = null)
    {
        $this->purgeExpiredContent();
        $config = isset($result['config']) && is_array($result['config']) ? $result['config'] : array();
        $usage = isset($result['usage']) && is_array($result['usage']) ? $result['usage'] : array();
        $success = !empty($result['success']);

        $promptTokens = isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : 0;
        $completionTokens = isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : 0;
        $totalTokens = isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : 0;
        $duration = isset($result['duration']) ? (int) $result['duration'] : 0;

        AiLog::create(array(
            'admin_id' => (int) $adminId,
            'app_id' => $appId ? (int) $appId : null,
            'model_id' => isset($config['model_id']) ? (int) $config['model_id'] : 0,
            'provider_id' => isset($config['provider_id']) ? (int) $config['provider_id'] : 0,
            'key_id' => isset($config['key_id']) ? (int) $config['key_id'] : null,
            'request_id' => ApiResponse::requestId(),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'duration' => $duration,
            'status_code' => isset($result['status_code']) ? (int) $result['status_code'] : 0,
            'has_error' => $success ? 0 : 1,
            'error_message' => $success ? null : (string) $result['error'],
            'prompt_content' => $this->limitContent($prompt),
            'response_content' => $this->limitContent(isset($result['content']) ? $result['content'] : null),
            'endpoint' => isset($config['api_url']) ? (string) $config['api_url'] : null,
            'ip' => (string) $ip,
            'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ));

        if (isset($config['key_id'])) {
            $keyId = (int) $config['key_id'];
            $this->gateway->touchKey($keyId);

            // 失败计数：成功归零，失败自增并达阈值熔断
            if ($success) {
                $this->gateway->resetFailureCount($keyId);
            } else {
                $this->gateway->bumpFailureCount($keyId);
            }
        }
    }

    /**
     * 异步任务成功后补记用量（plan.md 阶段 5）。
     *
     * 图像/视频按张/秒计费而非 token，一期口径：token 记 0，
     * 供应商返回的原始 usage 存 metadata 留档，单价模型扩展后再回填。
     * 调用时机：轮询首次到达 succeeded（终态早返回保证只记一次）。
     *
     * @param array $task ai_task 行（succeeded 态）
     * @param string $ip 触发本次轮询的管理员 IP
     * @return void
     */
    public function recordAsyncTaskSuccess(array $task, $ip)
    {
        $requestId = 'ai-task-' . (int) $task['id'];
        DB::beginTransaction();
        try {
            $lockedTask = DB::table('ai_task')->where('id', (int) $task['id'])->lock()->find();
            if (!$lockedTask || AiLog::where('request_id', $requestId)->exists()) {
                DB::commit();
                return;
            }
            $this->purgeExpiredContent();
            $resultData = !empty($task['result']) ? json_decode($task['result'], true) : array();
            if (!is_array($resultData)) {
                $resultData = array();
            }
            $raw = isset($resultData['raw']) && is_array($resultData['raw']) ? $resultData['raw'] : array();
            $usageRaw = isset($raw['usage']) && is_array($raw['usage']) ? $raw['usage'] : array();
            $urls = isset($resultData['urls']) && is_array($resultData['urls']) ? $resultData['urls'] : array();

            $requestPayload = !empty($task['request_payload'])
                ? json_decode((string) $task['request_payload'], true) : array();
            $taskPrompt = is_array($requestPayload) && isset($requestPayload['prompt'])
                ? (string) $requestPayload['prompt'] : '';

            $duration = 0;
            if (!empty($task['created_at']) && !empty($task['updated_at'])) {
                $duration = max(
                    0,
                    (strtotime((string) $task['updated_at']) - strtotime((string) $task['created_at'])) * 1000
                );
            }

            $appId = !empty($task['app_id']) ? (int) $task['app_id'] : 0;
            $adminId = (int) $task['admin_id'];
            $keyId = !empty($task['key_id']) ? (int) $task['key_id'] : 0;

            AiLog::create(array(
                'admin_id' => $adminId,
                'app_id' => $appId ?: null,
                'model_id' => (int) $task['model_id'],
                'provider_id' => (int) $task['provider_id'],
                'key_id' => $keyId ?: null,
                'request_id' => $requestId,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'duration' => $duration,
                'status_code' => 200,
                'has_error' => 0,
                'error_message' => null,
                'prompt_content' => $this->limitContent($taskPrompt),
                'response_content' => $this->limitContent($urls ? implode("\n", $urls) : null),
                'endpoint' => null,
                'ip' => (string) $ip,
                'metadata' => json_encode(array(
                    'task_id' => (int) $task['id'],
                    'task_type' => isset($task['task_type']) ? (string) $task['task_type'] : '',
                    'phase' => 'async_complete',
                    'usage' => $usageRaw,
                ), JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
            ));
            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }

        if ($keyId > 0) {
            $this->gateway->touchKey($keyId);
            $this->gateway->resetFailureCount($keyId);
        }
    }

    /**
     * @param mixed $content
     * @return string|null
     */
    private function limitContent($content)
    {
        $content = $content === null ? '' : (string) $content;
        if ($content === '') {
            return null;
        }

        if (strlen($content) <= self::MAX_CONTENT_BYTES) {
            return $content;
        }
        if (function_exists('mb_strcut')) {
            return mb_strcut($content, 0, self::MAX_CONTENT_BYTES, 'UTF-8');
        }

        $limited = substr($content, 0, self::MAX_CONTENT_BYTES);
        while ($limited !== '' && !preg_match('//u', $limited)) {
            $limited = substr($limited, 0, -1);
        }

        return $limited;
    }

    /**
     * @return void
     */
    private function purgeExpiredContent()
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::CONTENT_RETENTION_DAYS * 86400);
        AiLog::where('created_at', '<', $cutoff)
            ->whereRaw('(prompt_content IS NOT NULL OR response_content IS NOT NULL)')
            ->update(array('prompt_content' => null, 'response_content' => null));
    }
}
