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

namespace Dou\Core\Service\Ai\Task;

use Dou\Core\Facade\DB;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\Ai\AiGateway;
use Dou\Core\Service\Ai\CredentialCipher;
use Dou\Core\Service\Ai\Driver\AsyncDriverInterface;
use Dou\Core\Service\Ai\Factory\DriverFactory;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 异步任务编排：提交 → 落库 → 轮询上游 → 提取结果 → 落库。
 *
 * 轮询由浏览器驱动（lazy polling），无常驻进程、无 cron；
 * 超时判定同样惰性完成：任务总耗时超过阈值仍未见终态时标记 timeout。
 */
class AsyncTaskPoller extends BaseService
{
    /** @var array 各类型任务的总耗时超时阈值（分钟） */
    const TIMEOUT_MINUTES_BY_TYPE = array(
        'video' => 30,
        'image' => 10,
    );

    /** @var AiGateway */
    private $gateway;

    /** @var AsyncTaskRepository */
    private $repository;

    /**
     * @param AiGateway $gateway
     * @param AsyncTaskRepository|null $repository 可注入仓储（测试用），空则默认实现
     */
    public function __construct(AiGateway $gateway, AsyncTaskRepository $repository = null)
    {
        $this->gateway = $gateway;
        $this->repository = $repository === null ? new AsyncTaskRepository() : $repository;
    }

    /**
     * 提交异步任务：落 ai_task 行 → 调驱动 submitTask → 回写 provider_task_id。
     *
     * @param array $config resolveConfig 产物
     * @param array $params 提交参数（prompt 等，驱动自定）
     * @param array $meta 可选 app_id / admin_id / user_id
     * @return array {success, mode, task_id, status, config, error}
     */
    public function submit(array $config, array $params, array $meta = array())
    {
        $driver = DriverFactory::make($config);
        if (!($driver instanceof AsyncDriverInterface)) {
            return $this->failure(lang('ai_task_async_unsupported'), $config);
        }

        $taskId = $this->repository->create(array(
            'provider_id' => $config['provider_id'],
            'model_id' => $config['model_id'],
            'key_id' => $config['key_id'],
            'app_id' => isset($meta['app_id']) ? (int) $meta['app_id'] : 0,
            'admin_id' => isset($meta['admin_id']) ? (int) $meta['admin_id'] : 0,
            'user_id' => isset($meta['user_id']) ? (int) $meta['user_id'] : 0,
            'task_type' => $config['model_type'],
            'status' => AsyncTaskRepository::STATUS_PENDING,
            'request_payload' => json_encode($params, JSON_UNESCAPED_UNICODE),
        ));

        $submitResult = $driver->submitTask($config, $params);
        $providerTaskId = isset($submitResult['provider_task_id']) ? (string) $submitResult['provider_task_id'] : '';
        $syncUrls = array();
        if (!empty($submitResult['sync']) && isset($submitResult['result']['urls']) && is_array($submitResult['result']['urls'])) {
            $syncUrls = $submitResult['result']['urls'];
        }
        if ($syncUrls) {
            $expiresAt = date('Y-m-d H:i:s', time() + 86400);
            $this->repository->markSucceeded($taskId, array('urls' => array_values($syncUrls)), $expiresAt);

            return array(
                'success' => true,
                'mode' => 'async',
                'task_id' => $taskId,
                'status' => AsyncTaskRepository::STATUS_SUCCEEDED,
                'result' => array('urls' => array_values($syncUrls)),
                'expires_at' => $expiresAt,
                'config' => $config,
                'error' => '',
            );
        }
        if ($providerTaskId === '' && isset($params['size']) && trim((string) $params['size']) !== '') {
            // 尺寸兜底（模型无关）：带自定义尺寸被上游拒绝（策略表滞后/未登记约束）时，
            // 丢弃 size 按上游默认尺寸重试一次 —— 宁可出图偏小偏糊（调用方按需裁切放大），
            // 也不让尺寸成为生成失败的原因。
            Log::warning('AI image size rejected, retry without size', array(
                'channel' => 'ai',
                'model_id' => $config['model_id'],
                'size' => (string) $params['size'],
                'error' => isset($submitResult['error']) ? (string) $submitResult['error'] : '',
            ));

            $retryParams = $params;
            unset($retryParams['size']);
            $submitResult = $driver->submitTask($config, $retryParams);
            $providerTaskId = isset($submitResult['provider_task_id']) ? (string) $submitResult['provider_task_id'] : '';
        }
        if ($providerTaskId === '') {
            $this->repository->markFailed($taskId, isset($submitResult['error']) ? $submitResult['error'] : lang('ai_task_no_provider_id'));

            $error = isset($submitResult['error']) && $submitResult['error'] !== ''
                ? $submitResult['error']
                : lang('ai_task_submit_no_id');

            return $this->failure($error, $config);
        }

        $status = isset($submitResult['status']) && $submitResult['status'] !== ''
            ? $submitResult['status']
            : AsyncTaskRepository::STATUS_RUNNING;
        $this->repository->markSubmitted($taskId, $providerTaskId, $status);

        return array(
            'success' => true,
            'mode' => 'async',
            'task_id' => $taskId,
            'status' => $status,
            'config' => $config,
            'error' => '',
        );
    }

    /**
     * 同步生成结果补录为已成功任务行（文生图同步端点）。
     *
     * 同步图像生成不走上游任务接口，但产物同样落 ai_task：
     * 前端按统一 task_id 轮询/预览，「使用此图」按任务行取字节，
     * 任务面板亦有留痕。
     *
     * @param array $config resolveConfig 产物
     * @param array $params 生成参数（prompt 等）
     * @param array $meta 可选 app_id / admin_id / user_id
     * @param array $urls 图片地址列表（http URL 或 data: URI）
     * @param string|null $expiresAt 结果过期时间（data: URI 不过期传 null）
     * @return int 任务 ID
     */
    public function recordSyncResult(array $config, array $params, array $meta, array $urls, $expiresAt = null)
    {
        $taskId = $this->repository->create(array(
            'provider_id' => $config['provider_id'],
            'model_id' => $config['model_id'],
            'key_id' => $config['key_id'],
            'app_id' => isset($meta['app_id']) ? (int) $meta['app_id'] : 0,
            'admin_id' => isset($meta['admin_id']) ? (int) $meta['admin_id'] : 0,
            'user_id' => isset($meta['user_id']) ? (int) $meta['user_id'] : 0,
            'task_type' => 'image',
            'status' => AsyncTaskRepository::STATUS_SUCCEEDED,
            'request_payload' => json_encode($params, JSON_UNESCAPED_UNICODE),
        ));
        $this->repository->markSucceeded($taskId, array('urls' => array_values($urls)), $expiresAt);

        return $taskId;
    }

    /**
     * 轮询一次任务状态（前端定时调用）。
     *
     * 终态（succeeded/failed/timeout）直接返回落库结果；
     * 非终态则查一次上游并刷新本地状态，返回最新 status 供前端决定是否继续轮询。
     *
     * @param mixed $taskId
     * @return array {success, mode, task_id, status, result, expires_at, error}
     */
    public function poll($taskId)
    {
        $taskId = (int) $taskId;
        $task = $this->repository->find($taskId);
        if (!$task) {
            return $this->failure(lang('ai_task_not_found'), null);
        }

        // 终态直接返回，不再查上游
        if ($task['status'] === AsyncTaskRepository::STATUS_SUCCEEDED) {
            return $this->taskResult($taskId, $task['status'], $task['result'], $task['expires_at']);
        }
        if ($task['status'] === AsyncTaskRepository::STATUS_FAILED
            || $task['status'] === AsyncTaskRepository::STATUS_TIMEOUT) {
            return $this->failure((string) $task['error'], null, $taskId, $task['status']);
        }

        // 用任务自身的密钥/供应商直查（不过配额/熔断筛选）：
        // 任务在途期间密钥被并行调用熔断或配额耗尽时，不应连累本任务的结果回收
        $config = $this->configForTask($task);
        if (!$config) {
            $this->repository->markFailed($taskId, lang('ai_task_config_unavailable'));

            return $this->failure(lang('ai_task_config_unavailable'), null, $taskId, AsyncTaskRepository::STATUS_FAILED);
        }

        // 惰性超时：任务总耗时超过阈值仍未终态，直接判定超时
        $timeoutMinutes = $this->timeoutMinutes((string) $task['task_type']);
        $elapsedMinutes = (time() - strtotime((string) $task['created_at'])) / 60;
        if ($elapsedMinutes > $timeoutMinutes) {
            $this->repository->markTimeout($taskId);

            return $this->failure(lang('ai_task_timeout'), null, $taskId, AsyncTaskRepository::STATUS_TIMEOUT);
        }

        $driver = DriverFactory::make($config);
        if (!($driver instanceof AsyncDriverInterface)) {
            $this->repository->markFailed($taskId, lang('ai_task_provider_no_async'));

            return $this->failure(lang('ai_task_provider_no_async'), null, $taskId, AsyncTaskRepository::STATUS_FAILED);
        }

        $pollResult = $driver->pollTask($config, (string) $task['provider_task_id']);
        $status = isset($pollResult['status']) ? (string) $pollResult['status'] : '';

        if ($status === AsyncTaskRepository::STATUS_SUCCEEDED) {
            $result = isset($pollResult['result']) && is_array($pollResult['result']) ? $pollResult['result'] : array();
            $expiresAt = isset($pollResult['expires_at']) ? (string) $pollResult['expires_at'] : null;
            $this->repository->markSucceeded($taskId, $result, $expiresAt);

            return $this->taskResult($taskId, AsyncTaskRepository::STATUS_SUCCEEDED, json_encode($result, JSON_UNESCAPED_UNICODE), $expiresAt);
        }

        if ($status === AsyncTaskRepository::STATUS_FAILED) {
            $error = isset($pollResult['error']) ? (string) $pollResult['error'] : lang('ai_task_provider_failed');
            $this->repository->markFailed($taskId, $error);

            return $this->failure($error, null, $taskId, AsyncTaskRepository::STATUS_FAILED);
        }

        // pending / running：保持原状态，刷新轮询时间
        $this->repository->touchPolled($taskId);

        return array(
            'success' => true,
            'mode' => 'async',
            'task_id' => $taskId,
            'status' => $task['status'] === AsyncTaskRepository::STATUS_PENDING
                ? $task['status']
                : AsyncTaskRepository::STATUS_RUNNING,
            'result' => null,
            'expires_at' => null,
            'error' => '',
        );
    }

    /**
     * 各类型任务超时阈值（分钟）。
     *
     * @param string $taskType
     * @return int
     */
    private function timeoutMinutes($taskType)
    {
        return isset(self::TIMEOUT_MINUTES_BY_TYPE[$taskType])
            ? self::TIMEOUT_MINUTES_BY_TYPE[$taskType]
            : 15;
    }

    /**
     * 按任务行重建轮询用配置（直查 ai_model/ai_provider/ai_key，不过可用性筛选）。
     *
     * 与 {@see AiGateway::resolveConfig()} 的差异：不去检查密钥配额/限流/熔断
     * ——任务已在上游执行，轮询只认提交时落库的这套关联。
     *
     * @param array $task ai_task 行
     * @return array|null 关联记录缺失（被物理删除）时返回 null
     */
    private function configForTask(array $task)
    {
        $model = DB::table('ai_model')->where('id', (int) $task['model_id'])->find();
        if (!$model) {
            return null;
        }

        $provider = DB::table('ai_provider')->where('id', (int) $model['provider_id'])->find();
        $key = DB::table('ai_key')->where('id', (int) $task['key_id'])->find();
        if (!$provider || !$key) {
            return null;
        }

        $providerCode = (string) $provider['code'];

        $baseUrl = rtrim((string) $provider['base_url'], '/');

        return array(
            'model_id' => (int) $model['id'],
            'model_code' => (string) $model['model_code'],
            'model_type' => isset($task['task_type']) ? (string) $task['task_type'] : 'image',
            'provider_id' => (int) $provider['id'],
            'provider_code' => $providerCode,
            'key_id' => (int) $key['id'],
            'api_key' => (new CredentialCipher())->decrypt((string) $key['api_key']),
            'api_url' => $baseUrl,
            'base_url' => $baseUrl,
            'stream_format' => ($providerCode === 'qwen' || $providerCode === 'dashscope') ? 'qwen' : 'openai',
        );
    }

    /**
     * 成功态响应：解码结果 JSON。
     *
     * @param int $taskId
     * @param string $status
     * @param string $resultJson
     * @param string|null $expiresAt
     * @return array
     */
    private function taskResult($taskId, $status, $resultJson, $expiresAt)
    {
        $result = json_decode((string) $resultJson, true);

        return array(
            'success' => true,
            'mode' => 'async',
            'task_id' => $taskId,
            'status' => $status,
            'result' => is_array($result) ? $result : null,
            'expires_at' => $expiresAt,
            'error' => '',
        );
    }

    /**
     * 失败/超时态响应。
     *
     * @param string $error
     * @param array|null $config
     * @param int $taskId
     * @param string $status
     * @return array
     */
    private function failure($error, $config, $taskId = 0, $status = '')
    {
        return array(
            'success' => false,
            'mode' => 'async',
            'task_id' => (int) $taskId,
            'status' => $status,
            'result' => null,
            'expires_at' => null,
            'config' => $config,
            'error' => (string) $error,
        );
    }
}
