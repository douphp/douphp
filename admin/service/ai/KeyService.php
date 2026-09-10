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

use Dou\Admin\Model\Ai\AiKey;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Ai\CredentialCipher;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 密钥（ai_key）
 *
 * 密钥的增删改由 ModelService::insertWithKeys / updateWithKeys 在模型
 * 编辑表单提交时统一处理；本服务仅提供单条密钥的底层 CRUD 与格式化能力，
 * 以及 reset（AJAX 即时重置失败计数）入口。
 */
class KeyService extends BaseService
{
    /**
     * 保证数据库中的 AI 凭据使用加密格式。
     *
     * @return void
     */
    public function ensureStoredCredentialsEncrypted()
    {
        CredentialCipher::rewrapStoredCredentials();
    }

    /**
     * 按供应商取其全部密钥（格式化后，供供应商编辑页内嵌表格使用）。
     *
     * @param mixed $providerId
     * @return array
     */
    public function getKeysByProvider($providerId)
    {
        $pid = (int) $providerId;
        if ($pid <= 0) {
            return array();
        }

        $list = array();
        foreach (AiKey::where('provider_id', $pid)->order('id DESC')->get() as $model) {
            $row = $model->toArray();
            $list[] = $this->formatKeyRow($row);
        }

        return $list;
    }

    /**
     * 取单条密钥的格式化数据（供 AJAX 重置后返回前端局部渲染）。
     *
     * @param mixed $id
     * @return array|null
     */
    public function getFormattedKey($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $model = AiKey::find($id);
        if (!$model) {
            return null;
        }

        return $this->formatKeyRow($model->toArray());
    }

    /**
     * 格式化单条密钥记录：脱敏、日期、状态文案等。
     *
     * 不含 provider_name（由调用方按需补充，避免逐行查询）。
     *
     * @param array $row
     * @return array
     */
    private function formatKeyRow(array $row)
    {
        $created_at = $row['created_at'] ? date('Y-m-d H:i', strtotime($row['created_at'])) : '';
        $last_used_at = $row['last_used_at'] ? date('Y-m-d H:i', strtotime($row['last_used_at'])) : '-';
        $expires_at = $row['expires_at'] ? date('Y-m-d H:i', strtotime($row['expires_at'])) : '';

        $masked_key = $row['api_key']
            ? substr($row['api_key'], 0, 8) . '***' . substr($row['api_key'], -4) : '';

        $config = !empty($row['config']) ? json_decode($row['config'], true) : array();
        if (!is_array($config)) {
            $config = array();
        }

        return array(
            'id' => $row['id'],
            'provider_id' => $row['provider_id'],
            'alias' => $row['alias'],
            'masked_key' => $masked_key,
            'expires_at' => $expires_at,
            'last_used_at' => $last_used_at,
            'failure_count' => $row['failure_count'],
            'created_at' => $created_at,
            'config' => $this->publicConfig($config),
            'baidu_secret' => '',
        );
    }

    /**
     * @param array $data
     * @return int 新增记录主键
     */
    public function insert(array $data)
    {
        $expires_at = !empty($data['expires_at']) ? $data['expires_at'] : null;

        $row = array(
            'provider_id' => (int) $data['provider_id'],
            'api_key' => $data['api_key'],
            'alias' => isset($data['alias']) ? trim($data['alias']) : '',
            'expires_at' => $expires_at,
            'config' => $this->mergeClientSecret($data, isset($data['config']) ? trim($data['config']) : '', ''),
        );

        $model = AiKey::create($row);
        $id = (int) $model->getKey();

        $logName = !empty($row['alias']) ? $row['alias'] : sprintf(lang('ai_key_id_fallback'), $id);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CREATE, 1, (string) $logName, 'ai_key');

        return $id;
    }

    /**
     * 更新密钥。
     *
     * api_key 留空时视为「不修改」（编辑页脱敏展示，用户未改动则不覆盖原值）。
     *
     * @param array $data
     * @return void
     */
    public function update(array $data)
    {
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.ai.model'));
        }
        $model = AiKey::find($id);
        if (!$model) {
            throw new DomainException(lang('illegal'), route('admin.ai.model'));
        }

        $expires_at = !empty($data['expires_at']) ? $data['expires_at'] : null;

        $row = array(
            'provider_id' => (int) $data['provider_id'],
            'alias' => isset($data['alias']) ? trim($data['alias']) : '',
            'expires_at' => $expires_at,
            'config' => $this->mergeClientSecret(
                $data,
                isset($data['config']) ? trim($data['config']) : '',
                (string) $model['config']
            ),
        );

        // api_key 仅在非空时更新（留空表示不修改，保留原密钥）
        if (!empty($data['api_key'])) {
            $row['api_key'] = $data['api_key'];
        }

        $model->fill($row, 'update')->save();

        $logName = !empty($row['alias']) ? $row['alias'] : sprintf(lang('ai_key_id_fallback'), $id);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $logName, 'ai_key');
    }

    /**
     * 把百度 client_secret（若填写）合并进 config JSON，其余键保留不动。
     *
     * 留空表示不修改（编辑页脱敏回显，未改动则不覆盖原值）。
     *
     * @param array $data
     * @param string $configRaw
     * @param string $existingRaw
     * @return string
     */
    private function mergeClientSecret(array $data, $configRaw, $existingRaw)
    {
        $existing = $existingRaw !== '' ? json_decode($existingRaw, true) : array();
        if (!is_array($existing)) {
            $existing = array();
        }
        $config = $configRaw !== '' ? json_decode($configRaw, true) : array();
        if (!is_array($config)) {
            $config = array();
        }
        foreach (array('client_secret', 'access_token', 'token_expires_at') as $sensitiveKey) {
            if (isset($existing[$sensitiveKey])) {
                $config[$sensitiveKey] = $existing[$sensitiveKey];
            }
        }

        $secret = isset($data['client_secret']) ? trim((string) $data['client_secret']) : '';
        if ($secret !== '') {
            $config['client_secret'] = $secret;
            unset($config['access_token'], $config['token_expires_at']);
        }

        return json_encode($config, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array $config
     * @return string
     */
    private function publicConfig(array $config)
    {
        unset($config['client_secret'], $config['access_token'], $config['token_expires_at']);

        return $config ? json_encode($config, JSON_UNESCAPED_UNICODE) : '';
    }

    /**
     * 重置密钥失败计数（熔断后的后台恢复入口）。
     *
     * @param mixed $id
     * @return void
     * @throws DomainException
     */
    public function resetKey($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.ai.model'));
        }
        $model = AiKey::find($id);
        if (!$model) {
            throw new DomainException(lang('illegal'), route('admin.ai.model'));
        }

        AiKey::whereKey($id)->update(array(
            'failure_count' => 0,
        ));

        $logName = !empty($model['alias']) ? $model['alias'] : sprintf(lang('ai_key_id_fallback'), $id);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $logName, 'ai_key');
    }
}
