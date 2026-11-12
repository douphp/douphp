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
use Dou\Core\Service\Ai\AiStreamer;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Chat\SessionStore;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * aigc 文本对话执行器：与客服 ChatRunner 分离，使用 aigc_session / aigc_message。
 */
class TextChatRunner extends BaseService
{
    const HISTORY_LIMIT = 20;

    /** @var AiGateway */
    private $gateway;

    /** @var AiStreamer */
    private $streamer;

    /** @var UsageRecorder */
    private $usage;

    /** @var QuotaService */
    private $quota;

    /** @var SessionStore */
    private $store;

    public function __construct(AiGateway $gateway, AiStreamer $streamer, UsageRecorder $usage, QuotaService $quota)
    {
        $this->gateway = $gateway;
        $this->streamer = $streamer;
        $this->usage = $usage;
        $this->quota = $quota;
        $this->store = new SessionStore('aigc_session', 'aigc_message', 'app_id');
    }

    /**
     * 解析本轮模型配置（会话沿用 → 应用首选 → 系统默认）。
     *
     * @param array $app
     * @param array|null $session
     * @param int $requestModelId
     * @return array|null
     */
    public function resolveTurnConfig(array $app, $session, $requestModelId)
    {
        $whitelist = array();
        if (!empty($app['model_ids'])) {
            $ids = json_decode((string) $app['model_ids'], true);
            if (is_array($ids)) {
                $whitelist = array_map('intval', $ids);
            }
        }

        $requestModelId = (int) $requestModelId;
        if ($requestModelId > 0) {
            if ($whitelist && !in_array($requestModelId, $whitelist, true)) {
                return null;
            }

            return $this->gateway->resolveConfig($requestModelId);
        }

        $candidates = array();
        if ($session && (int) $session['model_id'] > 0 && (!$whitelist || in_array((int) $session['model_id'], $whitelist, true))) {
            $candidates[] = (int) $session['model_id'];
        }
        if ($whitelist) {
            $candidates[] = (int) reset($whitelist);
        }
        foreach ($candidates as $modelId) {
            $config = $this->gateway->resolveConfig($modelId);
            if ($config) {
                return $config;
            }
        }

        return $this->gateway->resolveConfig(null);
    }

    /**
     * 执行一轮流式对话。
     *
     * @param array $app
     * @param array $session
     * @param string $prompt
     * @param array $config
     * @param int $userId
     * @param bool $deductQuota
     * @param string $ip
     * @param string $userAgent
     * @param bool $regenerate
     * @return array
     */
    public function runStreamTurn(array $app, array $session, $prompt, array $config, $userId, $deductQuota, $ip, $userAgent, $regenerate = false)
    {
        if ($regenerate) {
            $latest = $this->store->latestMessages($session['id'], 1);
            if ($latest && isset($latest[0]['role']) && $latest[0]['role'] === 'ai') {
                $this->store->deleteMessage((int) $latest[0]['id']);
            }
        }

        $messages = $this->buildMessages($app, $session, $prompt, $regenerate);

        if (!$regenerate) {
            $this->store->appendMessage($session['id'], 'user', $prompt, array(
                'sender_id' => (int) $userId,
                'ip' => $ip,
            ));
            $this->maybeRetitle($session, $prompt);
        }

        $this->streamer->prepareResponse();
        $result = $this->streamer->stream($config, $messages);

        $messageId = 0;
        if ($result['content'] !== '' || $result['error'] !== '') {
            $messageId = $this->store->appendMessage($session['id'], 'ai', $result['content'], array(
                'content_type' => 'markdown',
                'model_id' => $config['model_id'],
                'key_id' => $config['key_id'],
                'prompt_tokens' => $result['prompt_tokens'],
                'completion_tokens' => $result['completion_tokens'],
                'total_tokens' => $result['total_tokens'],
                'duration' => $result['duration'],
                'has_error' => $result['error'] !== '',
                'error_message' => $result['error'],
            ));
        }

        $this->usage->record($config, $result, (int) $userId, (int) $app['id'], (int) $session['id'], $ip, $userAgent, 0);

        if ($deductQuota && (int) $userId > 0 && (int) $result['total_tokens'] > 0) {
            $this->quota->deduct((int) $userId, (int) $result['total_tokens'], 1);
        }

        $result['message_id'] = (int) $messageId;

        return $result;
    }

    /**
     * 组装消息：system → 历史 → 本轮提问。
     *
     * @param array $app
     * @param array $session
     * @param string $prompt
     * @param bool $skipLastUser
     * @return array
     */
    public function buildMessages(array $app, array $session, $prompt, $skipLastUser = false)
    {
        $messages = array();
        $system = isset($app['default_prompt']) ? trim((string) $app['default_prompt']) : '';
        if ($system !== '') {
            $messages[] = array('role' => 'system', 'content' => $system);
        }

        $history = $this->store->latestMessages($session['id'], self::HISTORY_LIMIT);
        if ($skipLastUser && $history) {
            $last = $history[count($history) - 1];
            if (isset($last['role']) && $last['role'] === 'user') {
                array_pop($history);
            }
        }

        foreach ($history as $row) {
            if ($row['role'] === 'user') {
                $messages[] = array('role' => 'user', 'content' => (string) $row['content']);
            } elseif ($row['role'] === 'ai' && (string) $row['content'] !== '') {
                $messages[] = array('role' => 'assistant', 'content' => (string) $row['content']);
            }
        }

        $messages[] = array('role' => 'user', 'content' => (string) $prompt);

        return $messages;
    }

    /**
     * 首轮提问改写会话标题。
     *
     * @param array $session
     * @param string $prompt
     * @return void
     */
    private function maybeRetitle(array $session, $prompt)
    {
        if ((int) $session['message_count'] > 0) {
            return;
        }
        $title = trim((string) $prompt);
        if ($title === '') {
            return;
        }
        if (function_exists('mb_substr') && mb_strlen($title, 'UTF-8') > 20) {
            $title = mb_substr($title, 0, 20, 'UTF-8') . '...';
        }

        DB::table('aigc_session')->where('id', (int) $session['id'])->data(array('title' => $title))->update();
    }
}
