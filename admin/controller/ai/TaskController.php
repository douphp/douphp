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

namespace Dou\Admin\Controller\Ai;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Service\Ai\TaskService;
use Dou\Admin\Service\Ai\UsageRecorder;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Ai\AiGateway;
use Dou\Core\Service\Ai\RemoteUrlPolicy;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Client;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 异步任务（route=ai/task）：
 * - store：提交任务（POST，JSON 返回 task_id）
 * - show：轮询一次任务状态（GET，JSON）
 * - index：任务列表页
 * - destroy：删除任务
 */
class TaskController extends BaseController
{
    const MAX_IMAGE_BYTES = 15728640;

    /** @var TaskService */
    private $taskService;

    /** @var AiGateway */
    private $gateway;

    /** @var UsageRecorder */
    private $usageRecorder;

    /**
     * @param TaskService $taskService
     * @param AiGateway $gateway
     * @param UsageRecorder $usageRecorder
     */
    public function __construct(TaskService $taskService, AiGateway $gateway, UsageRecorder $usageRecorder)
    {
        $this->taskService = $taskService;
        $this->gateway = $gateway;
        $this->usageRecorder = $usageRecorder;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'ai',
            'submenu' => 'ai_task',
        );
    }

    /**
     * 任务列表页（route=ai/task/index）。
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $req = array(
            'status' => trim((string) $request->input('status', '')),
            'task_type' => trim((string) $request->input('task_type', '')),
            'page' => $request->integer('page', 1),
        );

        $bundle = $this->taskService->getIndexPageData($req);

        return $this->view('ai_task.htm', array(
            'ur_here' => lang('ai_task'),
            'rec' => 'default',
            'req' => $req,
            'task_list' => $bundle['task_list'],
            'model_names' => $bundle['model_names'],
            'provider_names' => $bundle['provider_names'],
            'admin_names' => $bundle['admin_names'],
            'pager' => $bundle['pager'],
        ));
    }

    /**
     * 提交异步任务（route=ai/task，POST → store）。
     *
     * @param Request $request
     * @return void
     */
    public function store(Request $request)
    {
        try {
            $result = $this->taskService->submit(
                $request->integer('app_id', 0),
                (string) $request->post('prompt', ''),
                (int) auth('admin')->id(),
                $request->post()
            );
        } catch (DomainException $e) {
            ApiResponse::throwError('AI_TASK_FAILED', $e->getMessage());
        }

        ApiResponse::throwSuccess($result);
    }

    /**
     * 轮询一次任务状态（route=ai/task/show，GET ?id=）。
     *
     * @param Request $request
     * @return void
     */
    public function show(Request $request)
    {
        try {
            $id = $request->integer('id', 0);
            $result = $this->gateway->pollAsyncTask($id);
            if (!$result['success']) {
                ApiResponse::throwError('AI_TASK_FAILED', $result['error']);
            }

            // 成功终态补记用量（poll 终态早返回，保证只落一次账）
            if ($result['status'] === 'succeeded') {
                $task = $this->taskService->findTask($id);
                if ($task) {
                    $this->usageRecorder->recordAsyncTaskSuccess($task, (string) $request->ip());
                }
            }

            ApiResponse::throwSuccess($result);
        } catch (DomainException $e) {
            ApiResponse::throwError('AI_TASK_FAILED', $e->getMessage());
        }
    }

    /**
     * 取任务产物图片字节（route=ai/task/image，POST task_id + index）。
     *
     * 图片地址只从任务行结果中解析（不接受客户端任意 URL，规避 SSRF）：
     * http URL 由服务端代取后 base64 返回，data: URI 直接截取 base64 段；
     * 前端还原为 Blob 注入表单图片字段，走既有上传通道持久化。
     *
     * @param Request $request
     * @return void
     */
    public function image(Request $request)
    {
        try {
            $data = $this->fetchTaskImageData(
                $request->integer('task_id', 0),
                $request->integer('index', 0)
            );
        } catch (DomainException $e) {
            ApiResponse::throwError('AI_TASK_FAILED', $e->getMessage());
        }

        ApiResponse::throwSuccess($data);
    }

    /**
     * 解析任务结果中的指定图片并取回 base64 字节。
     *
     * @param int $taskId 任务 ID
     * @param int $index 图片下标（result.urls 序号）
     * @return array {data: string, url: string}
     */
    private function fetchTaskImageData($taskId, $index)
    {
        $taskId = (int) $taskId;
        $index = (int) $index;

        $task = $this->taskService->findTask($taskId);
        if (!$task) {
            throw new DomainException(lang('ai_task_not_found'));
        }
        if ($task['status'] !== 'succeeded') {
            throw new DomainException(lang('ai_task_not_succeeded'));
        }

        $result = !empty($task['result']) ? json_decode((string) $task['result'], true) : array();
        $urls = is_array($result) && isset($result['urls']) && is_array($result['urls']) ? $result['urls'] : array();
        if (!isset($urls[$index])) {
            throw new DomainException(lang('illegal'));
        }

        $url = (string) $urls[$index];

        // data: URI（b64_json 产物）：直接截取 base64 段
        if (strpos($url, 'data:') === 0) {
            if (!preg_match('#^data:image/(?:jpeg|png|gif|webp);base64,#i', $url)) {
                throw new DomainException(lang('ai_image_fetch_failed'));
            }
            $comma = strpos($url, ',');
            if ($comma === false) {
                throw new DomainException(lang('ai_image_fetch_failed'));
            }
            $base64 = (string) substr($url, $comma + 1);
            if (strlen($base64) > (int) ceil(self::MAX_IMAGE_BYTES * 4 / 3) + 4) {
                throw new DomainException(lang('ai_image_fetch_failed'));
            }
            $binary = base64_decode($base64, true);
            if ($binary === false || strlen($binary) > self::MAX_IMAGE_BYTES || !$this->isSupportedImage($binary)) {
                throw new DomainException(lang('ai_image_fetch_failed'));
            }

            return array('data' => $base64, 'url' => $url);
        }
        $resolve = RemoteUrlPolicy::resolvePublic($url);
        if ($resolve === false) {
            throw new DomainException(lang('ai_image_fetch_failed'));
        }

        $response = Client::request('GET', $url, array(), array(), array(
            'timeout' => 60,
            'connect_timeout' => 10,
            'max_bytes' => self::MAX_IMAGE_BYTES,
            'resolve' => $resolve,
            'return_meta' => true,
        ));
        $contentType = isset($response['content_type']) ? strtolower((string) $response['content_type']) : '';
        if (!is_array($response)
            || (int) (isset($response['http_code']) ? $response['http_code'] : 0) !== 200
            || !isset($response['body'])
            || $response['body'] === ''
            || !empty($response['too_large'])
            || strpos($contentType, 'image/') !== 0
            || !$this->isSupportedImage($response['body'])) {
            throw new DomainException(lang('ai_image_fetch_failed'));
        }

        return array('data' => base64_encode($response['body']), 'url' => $url);
    }

    /**
     * @param string $binary
     * @return bool
     */
    private function isSupportedImage($binary)
    {
        if (!function_exists('getimagesizefromstring')) {
            return false;
        }
        $info = @getimagesizefromstring($binary);
        if (!is_array($info) || !isset($info[2])) {
            return false;
        }
        $allowed = array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF);
        if (defined('IMAGETYPE_WEBP')) {
            $allowed[] = IMAGETYPE_WEBP;
        }

        return in_array((int) $info[2], $allowed, true);
    }

    /**
     * 删除任务（route=ai/task/del）。
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.ai.task'));
        }

        $result = $this->taskService->delete($id, $request->post());

        return $this->respondDeleteResult($result);
    }
}
