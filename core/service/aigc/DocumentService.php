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

use Dou\Core\Service\Ai\AiGateway;
use Dou\Core\Service\Ai\Task\AsyncTaskRepository;
use Dou\Core\Service\Aigc\Document\PptxBuilder;
use Dou\Core\Service\Aigc\Document\XlsxBuilder;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * PPT / Excel 文档生成：AI 结构化输出 → 本地 Office 文件 → aigc_task 作品行。
 */
class DocumentService extends BaseService
{
    /** @var AiGateway */
    private $gateway;

    /** @var MediaStore */
    private $media;

    /** @var PptxBuilder */
    private $pptx;

    /** @var XlsxBuilder */
    private $xlsx;

    public function __construct(AiGateway $gateway, MediaStore $media, PptxBuilder $pptx, XlsxBuilder $xlsx)
    {
        $this->gateway = $gateway;
        $this->media = $media;
        $this->pptx = $pptx;
        $this->xlsx = $xlsx;
    }

    /**
     * 生成 PPTX。
     *
     * @param array $app
     * @param int $userId
     * @param string $prompt
     * @param array $options 可选 slides / style
     * @return array {success, task_id, result, config, usage, error}
     */
    public function generatePpt(array $app, $userId, $prompt, array $options = array())
    {
        $prompt = trim((string) $prompt);
        if ($prompt === '') {
            return $this->failure(lang('aigc_prompt_empty', '请输入生成主题'));
        }
        $maxSlides = isset($options['slides']) ? max(3, min(30, (int) $options['slides'])) : 12;
        $style = isset($options['style']) ? trim((string) $options['style']) : '';

        $schema = array(
            'type' => 'object',
            'properties' => array(
                'title' => array('type' => 'string'),
                'subtitle' => array('type' => 'string'),
                'slides' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'title' => array('type' => 'string'),
                            'bullets' => array('type' => 'array', 'items' => array('type' => 'string')),
                        ),
                        'required' => array('title', 'bullets'),
                    ),
                ),
            ),
            'required' => array('title', 'slides'),
        );

        $system = '你是专业的演示文稿策划。请输出结构清晰、每页 3-6 条要点的中文 PPT 大纲。'
            . '总页数不超过 ' . $maxSlides . ' 页，每条要点不超过 60 字。'
            . ($style !== '' ? '风格要求：' . $style . '。' : '');
        $messages = array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $prompt),
        );

        $modelId = $this->pickModelId($app);
        $result = $this->gateway->chatJson($messages, $schema, $modelId, array('timeout' => 180));
        if (empty($result['success']) || !is_array($result['data'])) {
            return $this->failure(isset($result['error']) && $result['error'] !== '' ? $result['error'] : lang('aigc_generate_failed', '生成失败'));
        }

        $data = $result['data'];
        $slides = isset($data['slides']) && is_array($data['slides']) ? array_slice($data['slides'], 0, $maxSlides) : array();
        $outline = array(
            'title' => isset($data['title']) ? (string) $data['title'] : $prompt,
            'subtitle' => isset($data['subtitle']) ? (string) $data['subtitle'] : '',
            'slides' => $slides,
        );

        $built = $this->pptx->build($outline);
        if (empty($built['ok'])) {
            return $this->failure(isset($built['error']) ? $built['error'] : 'pptx build failed');
        }

        $binary = (string) @file_get_contents($built['path']);
        @unlink($built['path']);
        if ($binary === '') {
            return $this->failure('pptx read failed');
        }

        $stored = $this->media->putBinary('ppt', 'pptx', $binary);
        if (empty($stored['ok'])) {
            return $this->failure(isset($stored['error']) ? $stored['error'] : 'pptx store failed');
        }

        $fileName = $this->safeFileName($outline['title']) . '.pptx';
        $taskResult = array(
            'files' => array(array(
                'path' => $stored['path'],
                'name' => $fileName,
                'format' => 'pptx',
                'size' => strlen($binary),
            )),
            'title' => $outline['title'],
            'outline' => $outline,
        );
        $taskId = $this->recordTask($app, $userId, 'ppt', $prompt, $taskResult, $result);

        return array(
            'success' => true,
            'task_id' => $taskId,
            'result' => $taskResult,
            'config' => isset($result['config']) ? $result['config'] : null,
            'usage' => isset($result['usage']) ? $result['usage'] : array(),
            'error' => '',
        );
    }

    /**
     * 生成 XLSX。
     *
     * @param array $app
     * @param int $userId
     * @param string $prompt
     * @param array $options 可选 sheets / columns
     * @return array {success, task_id, result, config, usage, error}
     */
    public function generateExcel(array $app, $userId, $prompt, array $options = array())
    {
        $prompt = trim((string) $prompt);
        if ($prompt === '') {
            return $this->failure(lang('aigc_prompt_empty', '请输入生成主题'));
        }
        $maxSheets = isset($options['sheets']) ? max(1, min(5, (int) $options['sheets'])) : 1;

        $schema = array(
            'type' => 'object',
            'properties' => array(
                'title' => array('type' => 'string'),
                'sheets' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'name' => array('type' => 'string'),
                            'headers' => array('type' => 'array', 'items' => array('type' => 'string')),
                            'rows' => array('type' => 'array', 'items' => array('type' => 'array', 'items' => array('type' => 'string'))),
                        ),
                        'required' => array('name', 'headers', 'rows'),
                    ),
                ),
            ),
            'required' => array('title', 'sheets'),
        );

        $system = '你是专业的数据表格策划。请输出规范的中文表格数据：每表最多 20 列、200 行，'
            . '表头简洁明确，数据需完整可读。最多输出 ' . $maxSheets . ' 个工作表。';
        $messages = array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $prompt),
        );

        $modelId = $this->pickModelId($app);
        $result = $this->gateway->chatJson($messages, $schema, $modelId, array('timeout' => 180));
        if (empty($result['success']) || !is_array($result['data'])) {
            return $this->failure(isset($result['error']) && $result['error'] !== '' ? $result['error'] : lang('aigc_generate_failed', '生成失败'));
        }

        $data = $result['data'];
        $payload = array(
            'title' => isset($data['title']) ? (string) $data['title'] : $prompt,
            'sheets' => isset($data['sheets']) && is_array($data['sheets']) ? array_slice($data['sheets'], 0, $maxSheets) : array(),
        );

        $built = $this->xlsx->build($payload);
        if (empty($built['ok'])) {
            return $this->failure(isset($built['error']) ? $built['error'] : 'xlsx build failed');
        }

        $binary = (string) @file_get_contents($built['path']);
        @unlink($built['path']);
        if ($binary === '') {
            return $this->failure('xlsx read failed');
        }

        $stored = $this->media->putBinary('excel', 'xlsx', $binary);
        if (empty($stored['ok'])) {
            return $this->failure(isset($stored['error']) ? $stored['error'] : 'xlsx store failed');
        }

        $fileName = $this->safeFileName($payload['title']) . '.xlsx';
        $taskResult = array(
            'files' => array(array(
                'path' => $stored['path'],
                'name' => $fileName,
                'format' => 'xlsx',
                'size' => strlen($binary),
            )),
            'title' => $payload['title'],
        );
        $taskId = $this->recordTask($app, $userId, 'excel', $prompt, $taskResult, $result);

        return array(
            'success' => true,
            'task_id' => $taskId,
            'result' => $taskResult,
            'config' => isset($result['config']) ? $result['config'] : null,
            'usage' => isset($result['usage']) ? $result['usage'] : array(),
            'error' => '',
        );
    }

    /**
     * 写入作品任务行（同步完成）。
     *
     * @param array $app
     * @param int $userId
     * @param string $taskType
     * @param string $prompt
     * @param array $result
     * @param array $aiResult
     * @return int
     */
    private function recordTask(array $app, $userId, $taskType, $prompt, array $result, array $aiResult)
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
            'request_payload' => json_encode(array('prompt' => $prompt), JSON_UNESCAPED_UNICODE),
        ));
        $repo->markSucceeded($taskId, $result);

        return $taskId;
    }

    /**
     * 取应用绑定的首个模型 ID。
     *
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
     * 下载文件名安全化。
     *
     * @param string $name
     * @return string
     */
    private function safeFileName($name)
    {
        $name = trim((string) $name);
        $name = preg_replace('/[\\\\\/:*?"<>|]+/u', '', $name);
        $name = $name !== '' ? $name : 'aigc';
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 40, 'UTF-8');
        } else {
            $name = substr($name, 0, 40);
        }

        return $name;
    }

    /**
     * @param string $error
     * @return array
     */
    private function failure($error)
    {
        return array(
            'success' => false,
            'task_id' => 0,
            'result' => null,
            'config' => null,
            'usage' => array(),
            'error' => (string) $error,
        );
    }
}
