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

namespace Dou\Core\Service\Ai;

use Dou\Core\Facade\DB;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\Ai\Driver\AsyncDriverInterface;
use Dou\Core\Service\Ai\Driver\ImageGenerationInterface;
use Dou\Core\Service\Ai\Factory\DriverFactory;
use Dou\Core\Service\Ai\Task\AsyncTaskPoller;
use Dou\Core\Service\BaseService;
use Dou\Core\Web\Http\Client;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * AI 模型网关：供应商 / 模型 / 密钥解析 + 非流式对话 + 结构化（JSON）输出。
 *
 * 无 per-request 状态：模型、消息、运行参数全部显式入参；会话 / 配额 / 日志
 * 等领域逻辑由各端 service 自行处理，本类只负责「拿配置、发请求、收内容」。
 */
class AiGateway extends BaseService
{
    /**
     * 连续失败达此阈值视为熔断：候选密钥筛选时排除（failure_count >= 阈值）。
     */
    const FAILURE_THRESHOLD = 5;

    const FAILURE_COOLDOWN_SECONDS = 300;

    /**
     * 解析一次调用所需的完整配置（模型 → 供应商 → 密钥 → 端点）。
     *
     * @param int|null $modelId 模型 ID，空取默认模型
     * @return array|null 配置数组；任一环节缺失返回 null
     */
    public function resolveConfig($modelId = null)
    {
        $model = $modelId ? $this->getModel($modelId) : $this->getDefaultModel();
        if (!$model) {
            return null;
        }

        $provider = DB::table('ai_provider')
            ->where('id', $model['provider_id'])
            ->find();
        if (!$provider || empty($provider['status'])) {
            // 供应商不存在或已被停用
            return null;
        }

        $key = $this->getAvailableKey($provider['id']);
        if (!$key) {
            return null;
        }
        $credentialCipher = new CredentialCipher();
        $apiKey = $credentialCipher->decrypt((string) $key['api_key']);
        if ($apiKey !== '' && !$credentialCipher->isEncrypted((string) $key['api_key'])) {
            $encryptedApiKey = $credentialCipher->encrypt($apiKey);
            if (strlen($encryptedApiKey) <= 255) {
                DB::table('ai_key')->where('id', (int) $key['id'])->data(array(
                    'api_key' => $encryptedApiKey,
                ))->update();
            }
        }
        $keyConfigRaw = isset($key['config']) ? (string) $key['config'] : '';
        if ($keyConfigRaw !== '' && !$credentialCipher->isEncrypted($keyConfigRaw)) {
            $encryptedConfig = $credentialCipher->encrypt($keyConfigRaw);
            if (strlen($encryptedConfig) <= 65535) {
                DB::table('ai_key')->where('id', (int) $key['id'])->data(array(
                    'config' => $encryptedConfig,
                ))->update();
            }
        }

        $providerConfig = $provider['config'] ? json_decode($provider['config'], true) : array();
        if (!is_array($providerConfig)) {
            $providerConfig = array();
        }

        $streamFormat = ($provider['code'] === 'qwen' || $provider['code'] === 'dashscope') ? 'qwen' : 'openai';

        $providerCode = isset($provider['code']) ? $provider['code'] : '';
        // 驱动标识统一由 DriverFactory 路由（与 make() 同规则），避免两处维护
        $driverCode = DriverFactory::code(array(
            'provider_code' => $providerCode,
            'stream_format' => $streamFormat,
        ));

        // 模型类型字段已移除：统一走对话端点（OpenAI 兼容协议下多模态与文本同端点）。
        // 图像/视频等异步生成端点由驱动按供应商硬编码（见 BailianDriver / VolcanoDriver）。
        $modelType = 'text';
        // 端点自定义并入 config：config JSON 的 endpoints 键（原独立 endpoints 字段已合并）
        $endpointConfig = array();
        if (isset($providerConfig['endpoints']) && is_array($providerConfig['endpoints'])) {
            $endpointConfig = $providerConfig['endpoints'];
        }
        // 解析优先级：config.endpoints（text 键）> 供应商预设 > /chat/completions 兜底
        $presetByProvider = array(
            'baidu' => array(
                // base_url 已含 /rpc/2.0/ai_custom/v1/wenxinworkshop 前缀，模型名由驱动拼入
                'text' => '/chat',
            ),
        );
        if (isset($endpointConfig[$modelType])) {
            $endpointPath = $endpointConfig[$modelType];
        } elseif (isset($presetByProvider[$providerCode][$modelType])) {
            $endpointPath = $presetByProvider[$providerCode][$modelType];
        } else {
            $endpointPath = '/chat/completions';
        }

        return array(
            'model_id' => (int) $model['id'],
            'model_code' => $model['model_code'],
            'model_name' => $model['name'],
            'model_type' => $modelType,
            // 是否接受参考图（图生图）：按模型代码判定，弹窗据此决定是否显示「图片素材」栏
            'image_input' => ImageInputSupport::supported(isset($model['model_code']) ? $model['model_code'] : ''),
            'provider_id' => (int) $provider['id'],
            'provider_code' => $provider['code'],
            'key_id' => (int) $key['id'],
            'api_key' => $apiKey,
            'api_url' => rtrim($provider['base_url'], '/') . $endpointPath,
            'base_url' => rtrim($provider['base_url'], '/'),
            'max_tokens' => $model['max_tokens'] ? (int) $model['max_tokens'] : 2048,
            'temperature' => isset($providerConfig['temperature']) ? (float) $providerConfig['temperature'] : 0.7,
            'stream_format' => $streamFormat,
            'driver' => $driverCode,
            // 是否支持 response_format.json_schema 强约束（供应商 config JSON 中声明）
            'supports_json_schema' => !empty($providerConfig['supports_json_schema']),
        );
    }

    /**
     * @param mixed $modelId
     * @return array|null
     */
    public function getModel($modelId)
    {
        return DB::table('ai_model')
            ->where('id', (int) $modelId)
            ->find();
    }

    /**
     * 兜底模型：未指定模型时取 ID 最小的模型（id ASC）。
     *
     * @return array|null
     */
    public function getDefaultModel()
    {
        $models = DB::table('ai_model m')
            ->join('ai_provider p', 'm.provider_id = p.id', 'LEFT')
            ->where('p.status', 1)
            ->field('m.*')
            ->order('m.id ASC')
            ->select();
        foreach ((array) $models as $model) {
            if ($this->getAvailableKey((int) $model['provider_id'])) {
                return $model;
            }
        }

        return null;
    }

    /**
     * 取供应商当前最优可用密钥（失败次数少、最久未用优先）。
     *
     * 候选集按未熔断（failure_count < 阈值）+ 未过期筛选。
     *
     * @param mixed $providerId
     * @return array|null
     */
    public function getAvailableKey($providerId)
    {
        $now = date('Y-m-d H:i:s');
        $cooldownAt = date('Y-m-d H:i:s', time() - self::FAILURE_COOLDOWN_SECONDS);

        return DB::table('ai_key')
            ->where('provider_id', (int) $providerId)
            ->where(function ($q) use ($cooldownAt) {
                $q->where('failure_count', '<', self::FAILURE_THRESHOLD)
                    ->whereOr('last_used_at', '<', $cooldownAt);
            })
            ->where(function ($q) use ($now) {
                $q->where('expires_at', null)
                    ->whereOr('expires_at', '>', $now);
            })
            ->order('failure_count ASC, last_used_at ASC')
            ->find();
    }

    /**
     * 可用模型映射（id => 名称），仅含「启用供应商有可用密钥」的模型。
     *
     * 可用密钥判定与 {@see getAvailableKey()} 一致：未熔断（failure_count
     * < 阈值）且 expires_at 为空或未过期。
     *
     * @param string $modelIds 应用 model_ids 字段原文（JSON 数组），空为不限
     * @return array
     */
    public function getAvailableModels($modelIds = '')
    {
        $available = array();
        $query = DB::table('ai_model');

        if ($modelIds) {
            $ids = json_decode($modelIds, true);
            if (is_array($ids) && $ids) {
                $query->where('id', 'IN', array_map('intval', $ids));
            }
        }

        $query->order('id ASC');
        $now = date('Y-m-d H:i:s');
        $cooldownAt = date('Y-m-d H:i:s', time() - self::FAILURE_COOLDOWN_SECONDS);

        // 启用中的供应商 id 集，供循环内快速判断
        $enabledProviderIds = array();
        foreach (DB::table('ai_provider')->where('status', 1)->field('id')->select() as $provider) {
            $enabledProviderIds[] = (int) $provider['id'];
        }

        foreach ((array) $query->select() as $model) {
            if (!in_array((int) $model['provider_id'], $enabledProviderIds)) {
                continue;
            }
            $hasKey = DB::table('ai_key')
                ->where('provider_id', $model['provider_id'])
                ->where(function ($q) use ($cooldownAt) {
                    $q->where('failure_count', '<', self::FAILURE_THRESHOLD)
                        ->whereOr('last_used_at', '<', $cooldownAt);
                })
                ->where(function ($q) use ($now) {
                    $q->where('expires_at', null)
                        ->whereOr('expires_at', '>', $now);
                })
                ->exists();
            if ($hasKey) {
                $available[$model['id']] = $model['name'];
            }
        }

        return $available;
    }

    /**
     * 模型列表，带 selected 标记，供后台应用表单复选。
     *
     * @param string $selectedIds JSON 数组原文
     * @return array
     */
    public function getModelList($selectedIds = '[]')
    {
        $modelList = DB::table('ai_model')->order('id ASC')->select();

        $selectedArray = array();
        if (is_string($selectedIds) && strpos(trim($selectedIds), '[') === 0) {
            $decoded = json_decode($selectedIds, true);
            if (is_array($decoded)) {
                $selectedArray = $decoded;
            }
        }

        foreach ($modelList as &$model) {
            $model['selected'] = in_array($model['id'], $selectedArray) ? 1 : 0;
        }

        return $modelList;
    }

    /**
     * 穿梭框模型列表（按供应商分组），供后台 AI 助手应用表单多选。
     *
     * @param string $selectedIds JSON 数组原文
     * @return array 每项含 provider_id、provider_name、models（id/name/selected）
     */
    public function getModelTransferList($selectedIds = '[]')
    {
        $selectedArray = array();
        if (is_string($selectedIds) && strpos(trim($selectedIds), '[') === 0) {
            $decoded = json_decode($selectedIds, true);
            if (is_array($decoded)) {
                $selectedArray = array_map('intval', $decoded);
            }
        }

        $models = DB::table('ai_model m')
            ->join('ai_provider p', 'm.provider_id = p.id', 'LEFT')
            ->field('m.id, m.provider_id, m.name, m.model_code, p.name AS provider_name, p.status AS provider_status')
            ->order('p.sort ASC, p.id ASC, m.id ASC')
            ->select();

        $groups = array();
        $providerAvailability = array();
        foreach ((array) $models as $model) {
            $pid = (int) $model['provider_id'];
            $selected = in_array((int) $model['id'], $selectedArray, true) ? 1 : 0;
            if (!isset($providerAvailability[$pid])) {
                $providerAvailability[$pid] = (int) $model['provider_status'] === 1
                    && (bool) $this->getAvailableKey($pid);
            }
            if (!$selected && !$providerAvailability[$pid]) {
                continue;
            }
            if (!isset($groups[$pid])) {
                $groups[$pid] = array(
                    'provider_id' => $pid,
                    'provider_name' => $model['provider_name'] !== null ? (string) $model['provider_name'] : '',
                    'models' => array(),
                );
            }

            $groups[$pid]['models'][] = array(
                'id' => (int) $model['id'],
                'name' => (string) $model['name'],
                'selected' => $selected,
            );
        }

        return array_values($groups);
    }

    /**
     * 模型分组列表（按供应商分组），供后台应用表单两级联动下拉。
     *
     * @param int $selectedId 已选模型 ID（0 = 未选）
     * @return array 每项含 provider_id、provider_name、selected（组内含已选模型时为
     *               1）、models（id/name/selected）
     */
    public function getModelGroupList($selectedId = 0)
    {
        $selectedId = (int) $selectedId;

        $models = DB::table('ai_model m')
            ->join('ai_provider p', 'm.provider_id = p.id', 'LEFT')
            ->field('m.id, m.provider_id, m.name, m.model_code, p.name AS provider_name, p.status AS provider_status')
            ->order('p.sort ASC, p.id ASC, m.id ASC')
            ->select();

        $groups = array();
        $providerAvailability = array();
        foreach ((array) $models as $model) {
            $pid = (int) $model['provider_id'];
            $selected = $selectedId > 0 && (int) $model['id'] === $selectedId ? 1 : 0;
            if (!isset($providerAvailability[$pid])) {
                $providerAvailability[$pid] = (int) $model['provider_status'] === 1
                    && (bool) $this->getAvailableKey($pid);
            }
            if (!$selected && !$providerAvailability[$pid]) {
                continue;
            }
            if (!isset($groups[$pid])) {
                $groups[$pid] = array(
                    'provider_id' => $pid,
                    'provider_name' => $model['provider_name'] !== null ? (string) $model['provider_name'] : '',
                    'selected' => 0,
                    'models' => array(),
                );
            }

            if ($selected) {
                $groups[$pid]['selected'] = 1;
            }
            $groups[$pid]['models'][] = array(
                'id' => (int) $model['id'],
                'name' => (string) $model['name'],
                'model_code' => (string) $model['model_code'],
                'selected' => $selected,
            );
        }

        return array_values($groups);
    }

    /**
     * 批量取模型名映射（id => name），供列表页一次 IN 查询。
     *
     * @param array $modelIds 模型 ID 集合
     * @return array
     */
    public function getModelNames(array $modelIds)
    {
        $modelIds = array_values(array_filter(array_map('intval', $modelIds)));
        if (!$modelIds) {
            return array();
        }

        $names = array();
        $rows = DB::table('ai_model')->where('id', 'IN', $modelIds)->select();
        foreach ((array) $rows as $row) {
            $names[(int) $row['id']] = $row['name'];
        }

        return $names;
    }

    /**
     * 供应商列表（按排序），供后台筛选下拉。
     *
     * @return array
     */
    public function getProviderList()
    {
        return DB::table('ai_provider')->order('sort ASC')->select();
    }

    /**
     * 非流式对话。
     *
     * @param array $messages [['role' => 'system|user|assistant', 'content' => '...'], ...]
     * @param int|null $modelId 模型 ID，空取默认
     * @param array $options 可选 temperature / max_tokens / timeout / response_format
     * @return array {success, content, usage, duration, config, error}
     */
    public function chat(array $messages, $modelId = null, array $options = array())
    {
        $config = $this->resolveConfig($modelId);
        if (!$config) {
            Log::error('AI config unavailable', array(
                'channel' => 'ai',
                'model_id' => (int) $modelId,
            ));

            return $this->failure(lang('ai_no_model_config'), null);
        }

        $driver = DriverFactory::make($config);
        $body = $driver->buildRequestBody($config, $messages, false, $options);
        $timeout = isset($options['timeout']) ? (int) $options['timeout'] : 120;
        $startTime = microtime(true);

        // URL 钩子：驱动可自定义请求地址（如百度拼 access_token 查询参数），默认用 api_url
        $url = method_exists($driver, 'resolveRequestUrl')
            ? $driver->resolveRequestUrl($config)
            : $config['api_url'];
        if ($url === '') {
            return $this->failure(lang('ai_api_credential_failed'), $config, 0);
        }

        $result = Client::request('POST', $url, json_encode($body), $driver->buildHeaders($config), array(
            'timeout' => $timeout,
            'return_meta' => true,
        ));
        $duration = (int) ((microtime(true) - $startTime) * 1000);

        if (!is_array($result)) {
            return $this->failure(lang('ai_http_request_failed'), $config, $duration);
        }

        $httpCode = isset($result['http_code']) ? (int) $result['http_code'] : 0;
        $responseBody = isset($result['body']) ? $result['body'] : '';

        if ($httpCode !== 200) {
            $errorData = json_decode($responseBody, true);
            $errorMsg = isset($errorData['error']['message'])
                ? $errorData['error']['message']
                : (isset($errorData['error_msg']) ? $errorData['error_msg'] : sprintf(lang('ai_http_error_code'), $httpCode));
            Log::error('AI request http failure', array(
                'channel' => 'ai',
                'http_code' => $httpCode,
                'error' => $errorMsg,
                'model_id' => $config['model_id'],
            ));

            return $this->failure(sprintf(lang('ai_api_request_failed'), $errorMsg), $config, $duration, $httpCode);
        }

        $decoded = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->failure(sprintf(lang('ai_json_parse_failed'), json_last_error_msg()), $config, $duration);
        }
        if (isset($decoded['error'])) {
            $errorMsg = isset($decoded['error']['message']) ? $decoded['error']['message'] : lang('ai_api_unknown_error');

            return $this->failure(sprintf(lang('ai_api_error'), $errorMsg), $config, $duration);
        }
        // 百度文心错误字段（error_code 非 0 即业务错误）
        if (isset($decoded['error_code']) && (int) $decoded['error_code'] !== 0) {
            $errorMsg = isset($decoded['error_msg']) ? $decoded['error_msg'] : lang('ai_api_unknown_error');

            return $this->failure(sprintf(lang('ai_api_error'), $errorMsg), $config, $duration);
        }

        $content = $driver->extractContent($decoded);
        if ($content === null) {
            return $this->failure(lang('ai_api_response_invalid'), $config, $duration);
        }

        // token 用量提取委托驱动（抹平供应商字段口径差异，如 DashScope 的 input/output_tokens）
        $usage = $driver->extractUsage($decoded);
        $usage = is_array($usage) ? $usage : array();

        return array(
            'success' => true,
            'mode' => 'sync',
            'content' => $content,
            'result' => null,
            'usage' => array(
                'prompt_tokens' => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : 0,
                'completion_tokens' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : 0,
                'total_tokens' => isset($usage['total_tokens'])
                    ? (int) $usage['total_tokens']
                    : ((isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : 0)
                        + (isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : 0)),
            ),
            'duration' => $duration,
            'status_code' => $httpCode,
            'config' => $config,
            'error' => '',
        );
    }

    /**
     * 结构化输出对话：要求 AI 返回符合 JSON Schema 的 JSON。
     *
     * 支持 response_format.json_schema 的供应商走 API 端强约束；
     * 其余供应商降级为 json_object + 在 system 消息中重述 schema。
     *
     * @param array $messages 对话消息
     * @param array $schema JSON Schema（object 或 array 包装均可）
     * @param int|null $modelId
     * @param array $options
     * @return array {success, data, content, usage, duration, config, error}
     */
    public function chatJson(array $messages, array $schema, $modelId = null, array $options = array())
    {
        $config = $this->resolveConfig($modelId);
        if (!$config) {
            return $this->failure(lang('ai_no_model_config'), null) + array('data' => null);
        }

        if ($config['driver'] === 'openai') {
            if (!empty($config['supports_json_schema'])) {
                $options['response_format'] = array(
                    'type' => 'json_schema',
                    'json_schema' => array(
                        'name' => 'payload',
                        'strict' => true,
                        'schema' => $schema,
                    ),
                );
            } else {
                $options['response_format'] = array('type' => 'json_object');
            }
        }

        // 双重保险：schema 重述进 system 消息（json_schema 强约束之外的供应商依赖该指令）
        $schemaInstruction = lang('ai_json_schema_instruction')
            . json_encode($schema, JSON_UNESCAPED_UNICODE);
        array_unshift($messages, array('role' => 'system', 'content' => $schemaInstruction));

        $result = $this->chat($messages, $modelId, $options);
        if (!$result['success']) {
            $result['data'] = null;

            return $result;
        }

        $result['data'] = $this->decodeJsonContent($result['content']);
        if ($result['data'] === null) {
            $result['success'] = false;
            $result['error'] = lang('ai_json_content_invalid');
        }

        return $result;
    }

    /**
     * 文生图统一入口：按目标模型的驱动能力分流。
     *
     * - 同步驱动（ImageGenerationInterface）：直接生成，结果补录为已成功任务行，
     *   返回 task_id + status=succeeded，前端统一走任务链路展示；
     *   百炼等同时实现异步接口的驱动，对仅支持同步的模型返回 async_only=false，
     *   其余模型返回 async_only 后回落到异步提交；
     * - 异步驱动（AsyncDriverInterface）：提交上游任务，返回 task_id 由前端轮询；
     * - 两者皆无：返回不支持错误（如对话模型）。
     *
     * @param int|null $modelId 模型 ID，空取默认
     * @param array $params prompt（必填）/ n / size
     * @param array $meta 可选 app_id / admin_id / user_id（任务行归属）
     * @return array {success, mode, task_id, status, result, expires_at, usage, duration, config, error}
     */
    public function generateImage($modelId, array $params = array(), array $meta = array())
    {
        $config = $this->resolveConfig($modelId);
        if (!$config) {
            Log::error('AI config unavailable', array(
                'channel' => 'ai',
                'model_id' => (int) $modelId,
            ));

            $result = $this->failure(lang('ai_no_model_config'), null);
            $result['mode'] = 'async';

            return $result;
        }
        $config['model_type'] = 'image';

        $driver = DriverFactory::make($config);

        if ($driver instanceof ImageGenerationInterface) {
            $startTime = microtime(true);
            $genResult = $driver->generateImage($config, $params);
            $duration = (int) ((microtime(true) - $startTime) * 1000);

            if (empty($genResult['async_only'])) {
                if (empty($genResult['success']) && isset($params['size']) && trim((string) $params['size']) !== '') {
                    // 尺寸兜底（模型无关）：带自定义尺寸被上游拒绝时，丢弃 size 按上游默认尺寸重试一次，
                    // 宁可出图偏小偏糊（调用方按需裁切放大），也不让尺寸成为生成失败的原因。
                    Log::warning('AI image size rejected, retry without size', array(
                        'channel' => 'ai',
                        'model_id' => $config['model_id'],
                        'size' => (string) $params['size'],
                        'error' => isset($genResult['error']) ? (string) $genResult['error'] : '',
                    ));

                    $retryParams = $params;
                    unset($retryParams['size']);
                    $startTime = microtime(true);
                    $genResult = $driver->generateImage($config, $retryParams);
                    $duration = (int) ((microtime(true) - $startTime) * 1000);
                }

                if (empty($genResult['success'])) {
                    $error = isset($genResult['error']) && $genResult['error'] !== ''
                        ? $genResult['error']
                        : lang('ai_generate_failed');
                    Log::error('AI image generation failure', array(
                        'channel' => 'ai',
                        'error' => $error,
                        'model_id' => $config['model_id'],
                    ));

                    $result = $this->failure($error, $config, $duration);
                    $result['mode'] = 'async';

                    return $result;
                }

                $urls = isset($genResult['urls']) && is_array($genResult['urls']) ? $genResult['urls'] : array();

                // http URL 有上游有效期（按 24 小时保守提示过期），data: URI 不过期
                $hasRemoteUrl = false;
                foreach ($urls as $url) {
                    if (strpos((string) $url, 'data:') !== 0) {
                        $hasRemoteUrl = true;
                        break;
                    }
                }
                $expiresAt = $hasRemoteUrl ? date('Y-m-d H:i:s', time() + 86400) : null;

                $taskId = (new AsyncTaskPoller($this))->recordSyncResult($config, $params, $meta, $urls, $expiresAt);

                return array(
                    'success' => true,
                    'mode' => 'async',
                    'task_id' => $taskId,
                    'status' => 'succeeded',
                    'result' => array('urls' => array_values($urls)),
                    'expires_at' => $expiresAt,
                    'usage' => array('prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0),
                    'duration' => $duration,
                    'config' => $config,
                    'error' => '',
                );
            }
        }

        if ($driver instanceof AsyncDriverInterface) {
            return (new AsyncTaskPoller($this))->submit($config, $params, $meta);
        }

        $result = $this->failure(lang('ai_image_unsupported'), $config);
        $result['mode'] = 'async';

        return $result;
    }

    /**
     * 提交异步任务（图像/视频生成等分钟级任务）。
     *
     * 立即返回内部 task_id 供前端轮询，不阻塞 PHP 进程；
     * 仅异步驱动（实现 AsyncDriverInterface）的模型可走此入口。
     *
     * @param mixed $modelId 模型 ID，空取默认
     * @param array $params 提交参数（prompt 等，驱动自定）
     * @param array $meta 可选 app_id / admin_id / user_id
     * @return array {success, mode, task_id, status, config, error}
     */
    public function submitAsyncTask($modelId, array $params, array $meta = array())
    {
        $config = $this->resolveConfig($modelId);
        if (!$config) {
            $result = $this->failure(lang('ai_no_model_config'), null);
            $result['mode'] = 'async';

            return $result;
        }
        $taskType = isset($meta['task_type']) ? trim((string) $meta['task_type']) : '';
        if ($taskType === 'image' || $taskType === 'video') {
            $config['model_type'] = $taskType;
        }

        return (new AsyncTaskPoller($this))->submit($config, $params, $meta);
    }

    /**
     * 轮询一次异步任务状态（前端定时调用）。
     *
     * @param mixed $taskId 内部任务 ID（submitAsyncTask 返回）
     * @return array {success, mode, task_id, status, result, expires_at, error}
     */
    public function pollAsyncTask($taskId)
    {
        return (new AsyncTaskPoller($this))->poll($taskId);
    }

    /**
     * 组装请求体（OpenAI 标准格式 / 千问 DashScope 格式）。
     *
     * 协议分支已下沉到 Driver（见 Driver/ 目录），本方法仅作兼容入口，
     * 供 AiStreamer 等既有调用方继续使用。
     *
     * @param array $config resolveConfig 产物
     * @param array $messages
     * @param bool $stream
     * @param array $options
     * @return array
     */
    public function buildRequestBody(array $config, array $messages, $stream = false, array $options = array())
    {
        return DriverFactory::make($config)->buildRequestBody($config, $messages, $stream, $options);
    }

    /**
     * 组装请求头（协议分支已下沉到 Driver）。
     *
     * @param array $config
     * @return array
     */
    public function buildHeaders(array $config)
    {
        return DriverFactory::make($config)->buildHeaders($config);
    }

    /**
     * 标记密钥最近使用时间。
     *
     * @param int $keyId
     * @return void
     */
    public function touchKey($keyId)
    {
        DB::table('ai_key')
            ->where('id', (int) $keyId)
            ->data(array('last_used_at' => date('Y-m-d H:i:s')))
            ->update();
    }

    /**
     * 失败计数自增；达阈值（FAILURE_THRESHOLD）后由候选筛选自然熔断。
     *
     * @param int $keyId
     * @return void
     */
    public function bumpFailureCount($keyId)
    {
        $keyId = (int) $keyId;
        DB::table('ai_key')->where('id', $keyId)->inc('failure_count', 1);

        Log::warning('AI key failure bumped', array(
            'channel' => 'ai',
            'key_id' => $keyId,
            'threshold' => self::FAILURE_THRESHOLD,
        ));
    }

    /**
     * 成功调用后重置失败计数。
     *
     * @param int $keyId
     * @return void
     */
    public function resetFailureCount($keyId)
    {
        DB::table('ai_key')
            ->where('id', (int) $keyId)
            ->data(array('failure_count' => 0))
            ->update();
    }

    /**
     * 容错解码 AI 输出的 JSON（剥离 Markdown 代码块包装）。
     *
     * @param string $content
     * @return array|null
     */
    private function decodeJsonContent($content)
    {
        $content = trim((string) $content);
        if (preg_match('/^```(?:json)?\s*(.+?)\s*```$/s', $content, $matches)) {
            $content = $matches[1];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param string $error
     * @param array|null $config
     * @param int $duration
     * @param int $statusCode
     * @return array
     */
    private function failure($error, $config, $duration = 0, $statusCode = 0)
    {
        return array(
            'success' => false,
            'mode' => 'sync',
            'content' => '',
            'result' => null,
            'usage' => array('prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0),
            'duration' => (int) $duration,
            'status_code' => (int) $statusCode,
            'config' => $config,
            'error' => $error,
        );
    }
}
