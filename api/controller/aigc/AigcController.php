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

namespace Dou\Api\Controller\Aigc;

use Dou\Api\Controller\BaseController;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Service\Aigc\GenerationRunner;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Service\Aigc\AigcService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * aigc 小程序 API：应用中心 / 应用详情 / 生成提交与轮询 / 作品 / 文件。
 */
class AigcController extends BaseController
{
    /** @var AigcService */
    private $aigcService;

    /** @var GenerationRunner */
    private $runner;

    public function __construct(AigcService $aigcService, GenerationRunner $runner)
    {
        $this->aigcService = $aigcService;
        $this->runner = $runner;
    }

    /**
     * 应用中心（游客可浏览）。
     *
     * @return Response
     */
    public function index()
    {
        return $this->appListResponse();
    }

    /**
     * 应用列表（扁平，与 Web /aigc/list 对齐）。
     *
     * @return Response
     */
    public function listing()
    {
        return $this->appListResponse();
    }

    /**
     * 应用详情：字段、模型与配额。
     *
     * @param Request $request
     * @return Response
     */
    public function app(Request $request)
    {
        $app = $this->aigcService->findBySlug((string) $request->input('slug', ''));
        if (!$app) {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('aigc_app_not_found', '应用不存在'), array(), 404);
        }

        return ApiResponse::success(array(
            'title' => $app['name'],
            'app' => $app,
            'field_list' => $this->aigcService->fields((int) $app['id']),
            'available_models' => $this->aigcService->availableModels($app),
            'quota_status' => $this->aigcService->quotaStatus($this->userId()),
        ));
    }

    /**
     * 提交生成任务。
     *
     * @param Request $request
     * @return Response
     */
    public function submit(Request $request)
    {
        if (($guard = $this->requireLogin()) !== null) {
            return $guard;
        }

        $app = $this->aigcService->findBySlug((string) $request->input('slug', ''));
        if (!$app) {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('aigc_app_not_found', '应用不存在'), array(), 404);
        }

        $post = $this->collectFields($request);
        if ($post === null) {
            return ApiResponse::error(ApiCodes::VALIDATION_FAILED, lang('aigc_field_invalid_json', '表单数据格式错误'), array(), 422);
        }

        $built = $this->aigcService->buildGenerationParams($app, $post);
        if (empty($built['ok'])) {
            return ApiResponse::error(ApiCodes::VALIDATION_FAILED, (string) $built['error'], array(), 422);
        }

        $result = $this->runner->submit(
            $app,
            $this->userId(),
            $built['params'],
            (string) $request->ip(),
            (string) $request->server('HTTP_USER_AGENT', '')
        );
        if (empty($result['success'])) {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, (string) $result['error'], array(), 422);
        }

        return ApiResponse::success($this->presentTask($result));
    }

    /**
     * 轮询任务。
     *
     * @param Request $request
     * @return Response
     */
    public function poll(Request $request)
    {
        if (($guard = $this->requireLogin()) !== null) {
            return $guard;
        }

        $result = $this->runner->poll($request->integer('id', 0), $this->userId(), false);
        if (empty($result['success'])) {
            return ApiResponse::error(
                isset($result['status']) && in_array($result['status'], array('failed', 'timeout'), true)
                    ? ApiCodes::BUSINESS_RULE_VIOLATION
                    : ApiCodes::NOT_FOUND,
                (string) $result['error'],
                array(),
                422
            );
        }

        return ApiResponse::success($this->presentTask($result));
    }

    /**
     * 我的作品。
     *
     * @param Request $request
     * @return Response
     */
    public function works(Request $request)
    {
        if (($guard = $this->requireLogin()) !== null) {
            return $guard;
        }

        $type = trim((string) $request->input('type', ''));
        $page = $request->integer('page', 1);
        $pageUrl = route('aigc.works') . ($type !== '' ? '&type=' . rawurlencode($type) : '');
        $bundle = $this->aigcService->works($this->userId(), $type, $page, $pageUrl);

        $list = array();
        foreach ((array) $bundle['list'] as $row) {
            $row['files'] = $this->presentFiles(isset($row['files']) ? $row['files'] : array());
            $list[] = $row;
        }

        return ApiResponse::success(array(
            'title' => lang('aigc_works', '我的作品'),
            'work_list' => $list,
            'pager' => $bundle['pager'],
            'req' => array('type' => $type),
        ));
    }

    /**
     * 产物预览 / 下载（归属校验后读私有磁盘）。
     *
     * @param Request $request
     * @return Response|void
     */
    public function file(Request $request)
    {
        if (($guard = $this->requireLogin()) !== null) {
            return $guard;
        }

        $file = $this->aigcService->resolveWorkFile(
            $request->integer('id', 0),
            $this->userId(),
            $request->integer('index', 0)
        );
        if (!$file) {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('aigc_file_not_found', '文件不存在'), array(), 404);
        }

        $mode = $request->input('mode', 'inline') === 'download' ? 'download' : 'inline';
        header('Content-Type: ' . $file['mime']);
        header('Content-Length: ' . filesize($file['path']));
        header('Content-Disposition: ' . ($mode === 'download' ? 'attachment' : 'inline') . '; filename="' . rawurlencode($file['name']) . '"');
        readfile($file['path']);
        exit;
    }

    /**
     * 删除作品。
     *
     * @param Request $request
     * @return Response
     */
    public function task_delete(Request $request)
    {
        if (($guard = $this->requireLogin()) !== null) {
            return $guard;
        }

        $result = $this->runner->delete($request->integer('id', 0), $this->userId(), false);
        if (empty($result['success'])) {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, (string) $result['error'], array(), 422);
        }

        return ApiResponse::success(array('deleted' => true));
    }

    /**
     * 应用中心公共响应。
     *
     * @return Response
     */
    private function appListResponse()
    {
        return ApiResponse::success(array(
            'title' => lang('aigc', 'AI应用'),
            'app_list' => $this->aigcService->listApps(),
        ));
    }

    /**
     * 提交参数：常规 POST + 小程序 fields_json（JSON 对象）。
     *
     * @param Request $request
     * @return array|null 解析失败返回 null
     */
    private function collectFields(Request $request)
    {
        $post = (array) $request->post();
        $raw = $request->input('fields_json', '');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return null;
            }
            $post['fields'] = $decoded;
        }
        unset($post['fields_json']);

        return $post;
    }

    /**
     * 任务结果出口：仅暴露文件索引 / 名称 / 格式 / 大小，不泄漏私有路径与上游 URL。
     *
     * @param array $result
     * @return array
     */
    private function presentTask(array $result)
    {
        return array(
            'task_id' => isset($result['task_id']) ? (int) $result['task_id'] : 0,
            'status' => isset($result['status']) ? (string) $result['status'] : '',
            'result' => $this->presentResultData(isset($result['result']) ? $result['result'] : null),
            'error' => isset($result['error']) ? (string) $result['error'] : '',
        );
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    private function presentResultData($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        if (isset($data['files'])) {
            $data['files'] = $this->presentFiles($data['files']);
        }
        unset($data['urls'], $data['path']);

        return $data;
    }

    /**
     * 文件列表：补 index，剔除 path。
     *
     * @param array $files
     * @return array
     */
    private function presentFiles($files)
    {
        $list = array();
        foreach ((array) $files as $index => $file) {
            if (!is_array($file)) {
                continue;
            }
            $list[] = array(
                'index' => (int) $index,
                'name' => isset($file['name']) ? (string) $file['name'] : '',
                'format' => isset($file['format']) ? (string) $file['format'] : '',
                'size' => isset($file['size']) ? (int) $file['size'] : 0,
            );
        }

        return $list;
    }

    /**
     * @return int
     */
    private function userId()
    {
        return (int) auth('api')->id();
    }

    /**
     * 登录守卫；未登录返回错误响应。
     *
     * @return Response|null
     */
    private function requireLogin()
    {
        if ($this->userId() > 0) {
            return null;
        }

        return ApiResponse::error(ApiCodes::UNAUTHORIZED, lang('aigc_tips_need_login', '请先登录'), array(), 401);
    }
}
