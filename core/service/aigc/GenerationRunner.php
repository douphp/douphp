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
use Dou\Core\Service\Ai\Task\AsyncTaskRepository;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * aigc 生成运行时：图片 / 视频 / 音频 / PPT / Excel 的提交、轮询、落盘、扣费与退款。
 */
class GenerationRunner extends BaseService
{
    /** @var AiGateway */
    private $gateway;

    /** @var QuotaService */
    private $quota;

    /** @var UsageRecorder */
    private $usage;

    /** @var MediaStore */
    private $media;

    /** @var DocumentService */
    private $documents;

    public function __construct(AiGateway $gateway, QuotaService $quota, UsageRecorder $usage, MediaStore $media, DocumentService $documents)
    {
        $this->gateway = $gateway;
        $this->quota = $quota;
        $this->usage = $usage;
        $this->media = $media;
        $this->documents = $documents;
    }

    /**
     * 提交生成请求。
     *
     * @param array $app
     * @param int $userId
     * @param array $params prompt / text / size / duration / resolution / voice / speed / slides / sheets / style
     * @param string $ip
     * @param string $userAgent
     * @return array {success, task_id, status, result, error}
     */
    public function submit(array $app, $userId, array $params, $ip = '', $userAgent = '')
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return $this->failure(lang('aigc_tips_need_login', '请先登录'));
        }

        $type = isset($app['type']) ? (string) $app['type'] : '';
        if (!in_array($type, array('image', 'video', 'audio', 'ppt', 'excel'), true)) {
            return $this->failure(lang('aigc_type_unsupported', '该应用类型不支持生成'));
        }

        $check = $this->checkQuota($app, $userId);
        if (!$check['valid']) {
            return $this->failure($check['message']);
        }

        $modelId = $this->pickModelId($app);
        $meta = array(
            'app_id' => (int) $app['id'],
            'user_id' => $userId,
        );

        if ($type === 'image') {
            return $this->submitImage($app, $userId, $modelId, $params, $meta, $ip, $userAgent);
        }
        if ($type === 'video') {
            return $this->submitVideo($app, $userId, $modelId, $params, $meta, $ip, $userAgent);
        }
        if ($type === 'audio') {
            return $this->submitAudio($app, $userId, $modelId, $params, $ip, $userAgent);
        }

        return $this->submitDocument($app, $userId, $type, $params, $ip, $userAgent);
    }

    /**
     * 轮询任务（校验归属 + 终态落盘 + 失败退款）。
     *
     * @param int $taskId
     * @param int $userId
     * @param bool $isAdmin
     * @return array {success, task_id, status, result, error}
     */
    public function poll($taskId, $userId, $isAdmin = false)
    {
        $repo = new AsyncTaskRepository(AsyncTaskRepository::AIGC_TABLE);
        $task = $repo->find($taskId);
        if (!$task) {
            return $this->failure(lang('aigc_task_not_found', '任务不存在'));
        }
        if (!$isAdmin && (int) $task['user_id'] !== (int) $userId) {
            return $this->failure(lang('aigc_task_not_found', '任务不存在'));
        }

        // 已成功且已落本地文件：直接返回
        $stored = $this->decodeResult($task['result']);
        if ($task['status'] === AsyncTaskRepository::STATUS_SUCCEEDED && !empty($stored['files'])) {
            return $this->success((int) $taskId, AsyncTaskRepository::STATUS_SUCCEEDED, $stored);
        }

        $result = $this->gateway->pollAsyncTask($taskId, AsyncTaskRepository::AIGC_TABLE);
        if (empty($result['success'])) {
            if (isset($result['status'])
                && ($result['status'] === AsyncTaskRepository::STATUS_FAILED || $result['status'] === AsyncTaskRepository::STATUS_TIMEOUT)
                && $this->isPaidTask($task)) {
                $this->quota->refund((int) $task['user_id'], 0, 1);
            }

            return $this->failure(isset($result['error']) ? $result['error'] : lang('aigc_generate_failed', '生成失败'), $taskId, isset($result['status']) ? $result['status'] : '');
        }

        if ($result['status'] !== AsyncTaskRepository::STATUS_SUCCEEDED) {
            return $this->success((int) $taskId, (string) $result['status'], null);
        }

        $payload = $this->decodeResult($result['result']);
        $files = $this->persistUrls((string) $task['task_type'], (int) $task['app_id'], (int) $task['user_id'], $payload);
        if ($files) {
            $payload['files'] = $files;
        }
        $repo->updateResult($taskId, $payload);
        if (isset($result['expires_at'])) {
            $payload['expires_at'] = $result['expires_at'];
        }

        return $this->success((int) $taskId, AsyncTaskRepository::STATUS_SUCCEEDED, $payload);
    }

    /**
     * 删除作品（连带本地文件）。
     *
     * @param int $taskId
     * @param int $userId
     * @param bool $isAdmin
     * @return array {success, error}
     */
    public function delete($taskId, $userId, $isAdmin = false)
    {
        $repo = new AsyncTaskRepository(AsyncTaskRepository::AIGC_TABLE);
        $task = $repo->find($taskId);
        if (!$task || (!$isAdmin && (int) $task['user_id'] !== (int) $userId)) {
            return array('success' => false, 'error' => lang('aigc_task_not_found', '任务不存在'));
        }

        $result = $this->decodeResult($task['result']);
        if (!empty($result['files']) && is_array($result['files'])) {
            foreach ($result['files'] as $file) {
                if (isset($file['path'])) {
                    $this->media->delete($file['path']);
                }
            }
        }
        $repo->delete($taskId);

        return array('success' => true, 'error' => '');
    }

    /**
     * 图片生成（同步或异步）。
     */
    private function submitImage(array $app, $userId, $modelId, array $params, array $meta, $ip, $userAgent)
    {
        $params['prompt'] = isset($params['prompt']) ? trim((string) $params['prompt']) : '';
        if ($params['prompt'] === '') {
            return $this->failure(lang('ai_image_prompt_empty', '图片生成描述不能为空'));
        }

        $result = $this->gateway->generateImage($modelId, $params, $meta, AsyncTaskRepository::AIGC_TABLE);
        if (empty($result['success'])) {
            return $this->failure(isset($result['error']) ? $result['error'] : lang('aigc_generate_failed', '生成失败'));
        }

        $this->deduct($app, $userId);
        $this->recordUsage($result, $userId, (int) $app['id'], isset($result['task_id']) ? (int) $result['task_id'] : 0, $ip, $userAgent);

        $taskId = isset($result['task_id']) ? (int) $result['task_id'] : 0;
        $payload = array('urls' => isset($result['result']['urls']) ? $result['result']['urls'] : array());
        if (!empty($result['result']['urls'])) {
            $files = $this->persistUrls('image', (int) $app['id'], $userId, $payload);
            if ($files) {
                $payload['files'] = $files;
                $repo = new AsyncTaskRepository(AsyncTaskRepository::AIGC_TABLE);
                $repo->updateResult($taskId, $payload);
            }
        }

        return $this->success($taskId, isset($result['status']) ? (string) $result['status'] : 'succeeded', $payload);
    }

    /**
     * 视频生成（异步）。
     */
    private function submitVideo(array $app, $userId, $modelId, array $params, array $meta, $ip, $userAgent)
    {
        $params['prompt'] = isset($params['prompt']) ? trim((string) $params['prompt']) : '';
        if ($params['prompt'] === '') {
            return $this->failure(lang('aigc_prompt_empty', '请输入生成主题'));
        }
        $meta['task_type'] = 'video';

        $result = $this->gateway->submitAsyncTask($modelId, $params, $meta, AsyncTaskRepository::AIGC_TABLE);
        if (empty($result['success'])) {
            return $this->failure(isset($result['error']) ? $result['error'] : lang('aigc_generate_failed', '生成失败'));
        }

        $this->deduct($app, $userId);
        $this->recordUsage($result, $userId, (int) $app['id'], isset($result['task_id']) ? (int) $result['task_id'] : 0, $ip, $userAgent);

        return $this->success((int) $result['task_id'], isset($result['status']) ? (string) $result['status'] : 'running', null);
    }

    /**
     * 语音合成（同步 TTS）。
     */
    private function submitAudio(array $app, $userId, $modelId, array $params, $ip, $userAgent)
    {
        $params['text'] = isset($params['text']) ? trim((string) $params['text']) : '';
        if ($params['text'] === '') {
            return $this->failure(lang('ai_audio_text_empty', '语音合成文本不能为空'));
        }

        $result = $this->gateway->generateAudio($modelId, $params, array('app_id' => (int) $app['id'], 'user_id' => $userId));
        if (empty($result['success'])) {
            return $this->failure(isset($result['error']) ? $result['error'] : lang('aigc_generate_failed', '生成失败'));
        }

        $format = isset($result['format']) && $result['format'] !== '' ? (string) $result['format'] : 'mp3';
        $stored = null;
        if (!empty($result['binary'])) {
            $stored = $this->media->putBinary('audio', $format, $result['binary']);
        } elseif (!empty($result['audio_url'])) {
            $stored = $this->media->download($result['audio_url'], 'audio', $format);
        }
        if (!$stored || empty($stored['ok'])) {
            return $this->failure(isset($stored['error']) ? $stored['error'] : lang('aigc_file_store_failed', '产物保存失败'));
        }

        $this->deduct($app, $userId);
        $taskResult = array(
            'files' => array(array(
                'path' => $stored['path'],
                'name' => 'tts_' . date('YmdHis') . '.' . $stored['extension'],
                'format' => $stored['extension'],
                'size' => 0,
            )),
            'voice' => isset($params['voice']) ? (string) $params['voice'] : '',
        );
        $taskId = $this->recordSyncTask($app, $userId, 'audio', $params, $taskResult, $result);
        $this->recordUsage($result, $userId, (int) $app['id'], $taskId, $ip, $userAgent);

        return $this->success($taskId, AsyncTaskRepository::STATUS_SUCCEEDED, $taskResult);
    }

    /**
     * PPT / Excel 文档生成。
     */
    private function submitDocument(array $app, $userId, $type, array $params, $ip, $userAgent)
    {
        $prompt = isset($params['prompt']) ? trim((string) $params['prompt']) : '';
        if ($type === 'ppt') {
            $doc = $this->documents->generatePpt($app, $userId, $prompt, $params);
            $taskType = 'ppt';
        } else {
            $doc = $this->documents->generateExcel($app, $userId, $prompt, $params);
            $taskType = 'excel';
        }

        if (empty($doc['success'])) {
            return $this->failure(isset($doc['error']) ? $doc['error'] : lang('aigc_generate_failed', '生成失败'));
        }

        $this->deduct($app, $userId);
        $this->recordUsage(array(
            'config' => $doc['config'],
            'usage' => $doc['usage'],
            'duration' => 0,
            'error' => '',
        ), $userId, (int) $app['id'], (int) $doc['task_id'], $ip, $userAgent);

        return $this->success((int) $doc['task_id'], AsyncTaskRepository::STATUS_SUCCEEDED, $doc['result']);
    }

    /**
     * 上游 URL 落本地（图片 / 视频）。
     *
     * @param string $taskType
     * @param int $appId
     * @param int $userId
     * @param array $payload
     * @return array
     */
    private function persistUrls($taskType, $appId, $userId, array $payload)
    {
        $urls = isset($payload['urls']) && is_array($payload['urls']) ? $payload['urls'] : array();
        if (!$urls) {
            return array();
        }
        $directory = $taskType === 'video' ? 'video' : 'image';
        $files = array();
        foreach (array_slice($urls, 0, 9) as $index => $url) {
            $downloaded = $this->media->download((string) $url, $directory);
            if (!empty($downloaded['ok'])) {
                $files[] = array(
                    'path' => $downloaded['path'],
                    'name' => $taskType . '_' . date('YmdHis') . '_' . $index . '.' . $downloaded['extension'],
                    'format' => $downloaded['extension'],
                    'size' => 0,
                );
            }
        }

        return $files;
    }

    /**
     * 写入同步完成的任务行。
     *
     * @param array $app
     * @param int $userId
     * @param string $taskType
     * @param array $params
     * @param array $result
     * @param array $aiResult
     * @return int
     */
    private function recordSyncTask(array $app, $userId, $taskType, array $params, array $result, array $aiResult)
    {
        $config = isset($aiResult['config']) && is_array($aiResult['config']) ? $aiResult['config'] : array();
        $repo = new AsyncTaskRepository(AsyncTaskRepository::AIGC_TABLE);
        $taskId = $repo->create(array(
            'provider_id' => isset($config['provider_id']) ? (int) $config['provider_id'] : 0,
            'model_id' => isset($config['model_id']) ? (int) $config['model_id'] : 0,
            'key_id' => isset($config['key_id']) ? (int) $config['key_id'] : 0,
            'app_id' => (int) $app['id'],
            'user_id' => (int) $userId,
            'task_type' => $taskType,
            'status' => AsyncTaskRepository::STATUS_SUCCEEDED,
            'request_payload' => json_encode($params, JSON_UNESCAPED_UNICODE),
        ));
        $repo->markSucceeded($taskId, $result);

        return $taskId;
    }

    /**
     * 配额校验（免费应用走每日限额，付费应用走订阅配额）。
     *
     * @param array $app
     * @param int $userId
     * @return array {valid, message}
     */
    private function checkQuota(array $app, $userId)
    {
        if (!empty($app['is_free'])) {
            return $this->quota->checkFreeAppDaily(
                (int) $userId,
                (int) $app['id'],
                isset($app['free_limit_type']) ? (string) $app['free_limit_type'] : 'requests',
                isset($app['free_limit_value']) ? (int) $app['free_limit_value'] : 0
            );
        }

        return $this->quota->check((int) $userId);
    }

    /**
     * 扣减一次生成请求。
     *
     * @param array $app
     * @param int $userId
     * @return void
     */
    private function deduct(array $app, $userId)
    {
        if (!empty($app['is_free'])) {
            return;
        }
        $this->quota->deduct((int) $userId, 0, 1);
    }

    /**
     * 记用量。
     */
    private function recordUsage(array $result, $userId, $appId, $taskId, $ip, $userAgent)
    {
        $config = isset($result['config']) && is_array($result['config']) ? $result['config'] : array();
        $usage = isset($result['usage']) && is_array($result['usage']) ? $result['usage'] : array();
        if (isset($result['duration'])) {
            $usage['duration'] = (int) $result['duration'];
        }
        if (isset($result['error'])) {
            $usage['error'] = (string) $result['error'];
        }
        if (!$config) {
            $config = array('model_id' => 0, 'provider_id' => 0, 'key_id' => 0);
        }
        $this->usage->record($config, $usage, $userId, $appId, 0, $ip, $userAgent, $taskId);
    }

    /**
     * 任务所属应用是否为付费应用（失败退款只对付费任务生效）。
     *
     * @param array $task
     * @return bool
     */
    private function isPaidTask(array $task)
    {
        $app = DB::table('aigc')->where('id', (int) $task['app_id'])->find();
        if (!$app) {
            return false;
        }

        return empty($app['is_free']);
    }

    /**
     * @param array $app
     * @return int|null
     */
    private function pickModelId(array $app)
    {
        if (!empty($app['model_ids'])) {
            $ids = json_decode((string) $app['model_ids'], true);
            if (is_array($ids)) {
                foreach ($ids as $id) {
                    if ((int) $id > 0) {
                        return (int) $id;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param string $json
     * @return array
     */
    private function decodeResult($json)
    {
        if (!$json) {
            return array();
        }
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @param int $taskId
     * @param string $status
     * @param array|null $result
     * @return array
     */
    private function success($taskId, $status, $result)
    {
        return array(
            'success' => true,
            'task_id' => (int) $taskId,
            'status' => (string) $status,
            'result' => $result,
            'error' => '',
        );
    }

    /**
     * @param string $error
     * @param int $taskId
     * @param string $status
     * @return array
     */
    private function failure($error, $taskId = 0, $status = '')
    {
        return array(
            'success' => false,
            'task_id' => (int) $taskId,
            'status' => (string) $status,
            'result' => null,
            'error' => (string) $error,
        );
    }
}
