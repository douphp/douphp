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
use Dou\Admin\Model\Ai\AiLog;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Ai\AiGateway;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 使用日志（ai_log，route=ai/log/...）
 */
class AiLogService extends BaseService
{
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
     * @param array $req
     * @return array log_list, pager, provider_list, model_list
     */
    public function getAdminIndexPageData(array $req)
    {
        $adminId = isset($req['admin_id']) ? (int) $req['admin_id'] : 0;
        $providerId = isset($req['provider_id']) ? (int) $req['provider_id'] : 0;
        $modelId = isset($req['model_id']) ? (int) $req['model_id'] : 0;
        $hasErrorRaw = isset($req['has_error']) ? trim((string) $req['has_error']) : '';
        $timeStart = isset($req['time_start']) ? trim((string) $req['time_start']) : '';
        $timeEnd = isset($req['time_end']) ? trim((string) $req['time_end']) : '';
        $page = isset($req['page']) ? $req['page'] : 1;

        $pageUrl = route('admin.ai.log');
        if ($adminId > 0) {
            $pageUrl .= '&admin_id=' . $adminId;
        }
        if ($providerId > 0) {
            $pageUrl .= '&provider_id=' . $providerId;
        }
        if ($modelId > 0) {
            $pageUrl .= '&model_id=' . $modelId;
        }
        if ($hasErrorRaw !== '') {
            $pageUrl .= '&has_error=' . rawurlencode($hasErrorRaw);
        }
        if ($timeStart !== '') {
            $pageUrl .= '&time_start=' . rawurlencode($timeStart);
        }
        if ($timeEnd !== '') {
            $pageUrl .= '&time_end=' . rawurlencode($timeEnd);
        }

        $result = AiLog::filterByAdminId($adminId)
            ->filterByProviderId($providerId)
            ->filterByModelId($modelId)
            ->filterByHasError($hasErrorRaw)
            ->filterByCreatedAtStart($timeStart)
            ->filterByCreatedAtEnd($timeEnd)
            ->applyDefaultOrder()
            ->paginate(15, $page, Util::normalizeQueryString($pageUrl));

        $log_list = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $created_at = $row['created_at'] ? date('Y-m-d H:i:s', strtotime($row['created_at'])) : '';

            $admin_name = AiLog::getAdminName($row['admin_id']);
            $provider_name = AiLog::getProviderName($row['provider_id']);
            $model_name = AiLog::getModelName($row['model_id']);
            $app_name = $row['app_id'] ? AiLog::getApplicationName($row['app_id']) : '-';
            if ($app_name === null || $app_name === '') {
                $app_name = '-';
            }

            $log_list[] = array(
                'id' => $row['id'],
                'admin_id' => $row['admin_id'],
                'admin_name' => $admin_name ? $admin_name : '-',
                'app_name' => $app_name,
                'provider_name' => $provider_name,
                'model_name' => $model_name,
                'key_id' => $row['key_id'],
                'prompt_tokens' => $row['prompt_tokens'],
                'completion_tokens' => $row['completion_tokens'],
                'total_tokens' => $row['total_tokens'],
                'duration' => $row['duration'],
                'status_code' => $row['status_code'],
                'has_error' => language()->dataLangFormat('ai_log_has_error_', $row['has_error']),
                'created_at' => $created_at,
            );
        }

        return array(
            'log_list' => $log_list,
            'pager' => $result['pager'],
            'provider_list' => $this->gateway->getProviderList(),
            'model_list' => $this->gateway->getModelList(),
        );
    }

    /**
     * @param mixed $id
     * @return array|null log
     */
    public function getViewBundle($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $logModel = AiLog::findAdminRow($id);
        if (!$logModel) {
            return null;
        }
        $log = $logModel->toArray();

        $adminName = AiLog::getAdminName($log['admin_id']);
        $log['admin_name'] = $adminName ? $adminName : '-';
        $log['provider_name'] = AiLog::getProviderName($log['provider_id']);
        $log['model_name'] = AiLog::getModelName($log['model_id']);
        $log['key_alias'] = $log['key_id'] ? AiKey::whereKey(intval($log['key_id']))->value('alias') : '';

        $log['created_at'] = $log['created_at'] ? date('Y-m-d H:i:s', strtotime($log['created_at'])) : '';

        if (!empty($log['metadata'])) {
            $log['metadata_formatted'] = json_encode(
                json_decode($log['metadata']),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            );
        }

        // AI 最终返回内容：JSON 则美化展示，纯文本原样
        if (!empty($log['response_content'])) {
            $decoded = json_decode($log['response_content']);
            $log['response_content_formatted'] = $decoded !== null
                ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                : $log['response_content'];
        }

        $log['has_error_format'] = $log['has_error'] ? lang('ai_log_has_error_1') : lang('ai_log_has_error_0');

        return array(
            'log' => $log,
        );
    }

    /**
     * @param mixed $id
     * @param array $post
     * @return array{message: string, back_url: string, timeout: string, confirm_url: string}
     * @throws DomainException
     */
    public function delete($id, array $post)
    {
        $id = (int) $id;
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.ai.log'));
        }
        if (!AiLog::findAdminRow($id)) {
            throw new DomainException(lang('illegal'), route('admin.ai.log'));
        }

        if (isset($post['confirm'])) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'ID:' . $id, 'ai_log');
            AiLog::destroy($id);

            return array(
                'message' => lang('del_succes'),
                'back_url' => route('admin.ai.log'),
            );
        }

        $msg = preg_replace('/d%/Ums', 'ID: ' . $id, lang('del_check'));
        return array(
            'message' => $msg,
            'back_url' => route('admin.ai.log'),
            'timeout' => '30',
            'confirm_url' => route('admin.ai.log.destroy', array('id' => $id)),
        );
    }

    /**
     * @param array $post
     * @return array{message: string, back_url: string}
     * @throws DomainException 参数不合法或动作类型不支持时抛出
     */
    public function action(array $post)
    {
        if (empty($post['checkbox']) || !is_array($post['checkbox'])) {
            throw new DomainException(lang('ai_log_select_empty'), route('admin.ai.log'));
        }

        $action = Arr::get($post, 'action', '');
        if ($action === 'del_all') {
            $ids = Check::intIds($post['checkbox']);
            if (empty($ids)) {
                throw new DomainException(lang('ai_log_select_empty'), route('admin.ai.log'));
            }
            AiLog::whereIn('id', $ids)->delete();
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'bulk:AiLog (' . count($ids) . ' items)', 'ai_log');
            return array('message' => lang('del_succes'), 'back_url' => route('admin.ai.log'));
        }

        throw new DomainException(lang('select_empty'), route('admin.ai.log'));
    }
}
