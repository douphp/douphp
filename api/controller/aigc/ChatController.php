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
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Service\Aigc\QuotaService;
use Dou\Core\Service\Aigc\TextChatRunner;
use Dou\Core\Service\Chat\SessionStore;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Service\Aigc\AigcService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * aigc 小程序文本对话：SSE、会话与消息。
 */
class ChatController extends BaseController
{
    /** @var AigcService */
    private $aigcService;

    /** @var TextChatRunner */
    private $runner;

    /** @var QuotaService */
    private $quota;

    /** @var SessionStore */
    private $store;

    public function __construct(AigcService $aigcService, TextChatRunner $runner, QuotaService $quota)
    {
        $this->aigcService = $aigcService;
        $this->runner = $runner;
        $this->quota = $quota;
        $this->store = new SessionStore('aigc_session', 'aigc_message', 'app_id');
    }

    /**
     * 流式对话（SSE）。
     *
     * @param Request $request
     * @return Response|void
     */
    public function stream(Request $request)
    {
        $userId = $this->userId();
        if ($userId <= 0) {
            return ApiResponse::error(ApiCodes::UNAUTHORIZED, lang('aigc_tips_need_login', '请先登录'), array(), 401);
        }

        $prompt = trim((string) $request->post('prompt', ''));
        if ($prompt === '') {
            return ApiResponse::error(ApiCodes::INVALID_PARAMS, lang('aigc_prompt_empty', '请输入内容'), array(), 422);
        }

        $session = $this->store->findBySn((string) $request->post('session_sn', ''));
        if (!$session || (int) $session['user_id'] !== $userId) {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('aigc_session_not_found', '会话不存在'), array(), 404);
        }

        $app = DB::table('aigc')->where('id', (int) $session['app_id'])->where('status', 1)->find();
        if (!$app || $app['type'] !== 'chat') {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('aigc_app_not_found', '应用不存在'), array(), 404);
        }

        if (!empty($app['is_free'])) {
            $check = $this->quota->checkFreeAppDaily($userId, (int) $app['id'], (string) $app['free_limit_type'], (int) $app['free_limit_value']);
        } else {
            $check = $this->quota->check($userId);
        }
        if (!$check['valid']) {
            return ApiResponse::error(ApiCodes::QUOTA_EXCEEDED, (string) $check['message'], array(), 422);
        }

        $config = $this->runner->resolveTurnConfig($app, $session, (int) $request->post('model_id', 0));
        if (!$config) {
            return ApiResponse::error(ApiCodes::AI_CONFIG_UNAVAILABLE, lang('aigc_no_model', '无可用的AI模型配置'), array(), 500);
        }

        $result = $this->runner->runStreamTurn(
            $app,
            $session,
            $prompt,
            $config,
            $userId,
            empty($app['is_free']),
            (string) $request->ip(),
            (string) $request->server('HTTP_USER_AGENT', ''),
            !empty($request->post('regenerate'))
        );

        if (!connection_aborted()) {
            echo 'data: ' . json_encode(array('message_id' => isset($result['message_id']) ? (int) $result['message_id'] : 0), JSON_UNESCAPED_UNICODE) . "\n\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        }
        exit;
    }

    /**
     * 新建会话。
     *
     * @param Request $request
     * @return Response
     */
    public function new_session(Request $request)
    {
        if (($guard = $this->requireLogin()) !== null) {
            return $guard;
        }

        $app = $this->aigcService->findBySlug((string) $request->post('slug', ''));
        if (!$app || $app['type'] !== 'chat') {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('aigc_app_not_found', '应用不存在'), array(), 404);
        }

        $config = $this->runner->resolveTurnConfig($app, null, (int) $request->post('model_id', 0));
        if (!$config) {
            return ApiResponse::error(ApiCodes::AI_CONFIG_UNAVAILABLE, lang('aigc_no_model', '无可用的AI模型配置'), array(), 500);
        }

        $session = $this->store->create(
            (int) $app['id'],
            $this->userId(),
            (int) $config['model_id'],
            (int) $config['provider_id'],
            (int) $config['key_id'],
            (string) $request->post('title', ''),
            (string) $request->ip(),
            (string) $request->server('HTTP_USER_AGENT', '')
        );
        if (!$session) {
            return ApiResponse::error(ApiCodes::SERVER_ERROR, lang('aigc_session_create_failed', '创建会话失败'), array(), 500);
        }

        return ApiResponse::success(array('session' => $this->presentSession($session)));
    }

    /**
     * 会话列表。
     *
     * @param Request $request
     * @return Response
     */
    public function sessions(Request $request)
    {
        if (($guard = $this->requireLogin()) !== null) {
            return $guard;
        }

        $app = $this->aigcService->findBySlug((string) $request->input('slug', ''));
        if (!$app) {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('aigc_app_not_found', '应用不存在'), array(), 404);
        }

        return ApiResponse::success(array(
            'sessions' => $this->aigcService->sessions((int) $app['id'], $this->userId()),
        ));
    }

    /**
     * 会话消息。
     *
     * @param Request $request
     * @return Response
     */
    public function messages(Request $request)
    {
        if (($guard = $this->requireLogin()) !== null) {
            return $guard;
        }

        $session = $this->store->findBySn((string) $request->input('session_sn', ''));
        if (!$session || (int) $session['user_id'] !== $this->userId()) {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('aigc_session_not_found', '会话不存在'), array(), 404);
        }

        $afterId = (int) $request->input('after_id', 0);
        $rows = $afterId > 0
            ? $this->store->messages((int) $session['id'], 100, $afterId)
            : $this->store->latestMessages((int) $session['id'], 100);

        $messages = array();
        foreach ((array) $rows as $row) {
            $messages[] = array(
                'id' => (int) $row['id'],
                'role' => (string) $row['role'],
                'content' => (string) $row['content'],
                'content_type' => (string) $row['content_type'],
                'has_error' => (int) $row['has_error'],
                'created_at' => date('Y-m-d H:i:s', strtotime((string) $row['created_at'])),
            );
        }

        return ApiResponse::success(array(
            'messages' => $messages,
            'session' => $this->presentSession($session),
        ));
    }

    /**
     * @param array $session
     * @return array
     */
    private function presentSession(array $session)
    {
        return array(
            'id' => (int) $session['id'],
            'session_sn' => (string) $session['session_sn'],
            'title' => $session['title'] !== '' ? (string) $session['title'] : lang('aigc_new_session', '新会话'),
            'status' => (string) $session['status'],
            'model_id' => (int) $session['model_id'],
            'message_count' => (int) $session['message_count'],
            'total_tokens' => (int) $session['total_tokens'],
            'created_at' => date('Y-m-d H:i', strtotime((string) $session['created_at'])),
            'updated_at' => date('Y-m-d H:i', strtotime((string) $session['updated_at'])),
        );
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
