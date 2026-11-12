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

namespace Dou\Admin\Service\Aigc;

use Dou\Admin\Model\Aigc\AigcUsageLog;
use Dou\Core\Facade\DB;
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
 * 后台会员 AI 使用日志（aigc_usage_log，route=aigc/usage_log/...）。
 */
class UsageLogService extends BaseService
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
     * 列表页数据。
     *
     * @param array $req 筛选请求（username / app_id / provider_id / model_id / has_error / page）
     * @return array log_list、pager、provider_list、model_list、app_options、user_not_found
     */
    public function getAdminIndexPageData(array $req)
    {
        $username = isset($req['username']) ? trim((string) $req['username']) : '';
        $appId = isset($req['app_id']) ? (int) $req['app_id'] : 0;
        $providerId = isset($req['provider_id']) ? (int) $req['provider_id'] : 0;
        $modelId = isset($req['model_id']) ? (int) $req['model_id'] : 0;
        $hasErrorRaw = isset($req['has_error']) ? trim((string) $req['has_error']) : '';
        $page = isset($req['page']) ? $req['page'] : 1;

        $pageUrl = route('admin.aigc.usage_log');
        $userId = 0;
        $userNotFound = false;
        if ($username !== '') {
            $userId = user() !== null ? (int) user()->findUserId($username) : 0;
            $userNotFound = $userId <= 0;
            $pageUrl .= '&username=' . rawurlencode($username);
        }
        if ($appId > 0) {
            $pageUrl .= '&app_id=' . $appId;
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

        $query = AigcUsageLog::filterByUserId($userId)
            ->filterByAigcId($appId)
            ->filterByProviderId($providerId)
            ->filterByModelId($modelId)
            ->filterByHasError($hasErrorRaw)
            ->applyDefaultOrder();
        // 输入了用户名但解析不到会员：返回空集，避免 0 被当作“不过滤”显示全表
        if ($userNotFound) {
            $query->whereRaw('1 = 0');
        }
        $result = $query->paginate(15, $page, Util::normalizeQueryString($pageUrl));

        $log_list = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();

            $user_info = ((int) $row['user_id'] > 0 && user() !== null) ? user()->format($row['user_id']) : false;
            if (!$user_info) {
                // 会员专属模块：无会员主体（如系统调用）统一显示占位
                $user_info = array('nickname' => '-', 'avatar' => '');
            }

            $log_list[] = array(
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'user' => $user_info,
                'app_name' => $row['app_id'] ? AigcUsageLog::getAigcName($row['app_id']) : '-',
                'provider_name' => AigcUsageLog::getProviderName($row['provider_id']),
                'model_name' => AigcUsageLog::getModelName($row['model_id']),
                'prompt_tokens' => $row['prompt_tokens'],
                'completion_tokens' => $row['completion_tokens'],
                'total_tokens' => $row['total_tokens'],
                'duration' => $row['duration'],
                'status_code' => $row['status_code'],
                'has_error' => language()->dataLangFormat('aigc_usage_log_has_error_', $row['has_error']),
                'created_at' => !empty($row['created_at']) ? date('Y-m-d H:i:s', strtotime($row['created_at'])) : '',
            );
        }

        return array(
            'log_list' => $log_list,
            'pager' => $result['pager'],
            'provider_list' => $this->gateway->getProviderList(),
            'model_list' => $this->gateway->getModelList(),
            'app_options' => (array) DB::table('aigc')->field('id, name')->order('sort ASC, id ASC')->select(),
            'user_not_found' => $userNotFound,
        );
    }

    /**
     * 详情页数据。
     *
     * @param mixed $id 日志主键
     * @return array|null log
     */
    public function getViewBundle($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $logModel = AigcUsageLog::find($id);
        if (!$logModel) {
            return null;
        }
        $log = $logModel->toArray();

        $user_info = ((int) $log['user_id'] > 0 && user() !== null) ? user()->format($log['user_id']) : false;
        if (!$user_info) {
            $user_info = array('nickname' => '-', 'avatar' => '');
        }
        $log['user'] = $user_info;
        $log['app_name'] = $log['app_id'] ? AigcUsageLog::getAigcName($log['app_id']) : '-';
        $log['provider_name'] = AigcUsageLog::getProviderName($log['provider_id']);
        $log['model_name'] = AigcUsageLog::getModelName($log['model_id']);

        $log['created_at'] = !empty($log['created_at']) ? date('Y-m-d H:i:s', strtotime($log['created_at'])) : '';

        if (!empty($log['metadata'])) {
            $log['metadata_formatted'] = json_encode(
                json_decode($log['metadata']),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            );
        }

        $log['has_error_format'] = $log['has_error'] ? lang('aigc_usage_log_has_error_1') : lang('aigc_usage_log_has_error_0');

        return array(
            'log' => $log,
        );
    }

    /**
     * 单条删除（二次确认）。
     *
     * @param mixed $id 日志主键
     * @param array $post 含 confirm 时表示用户已确认
     * @return array{message: string, back_url: string, timeout?: string, confirm_url?: string}
     * @throws DomainException
     */
    public function delete($id, array $post)
    {
        $id = (int) $id;
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.aigc.usage_log'));
        }
        if (!AigcUsageLog::find($id)) {
            throw new DomainException(lang('illegal'), route('admin.aigc.usage_log'));
        }

        if (isset($post['confirm'])) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'ID:' . $id, 'aigc_usage_log');
            AigcUsageLog::destroy($id);

            return array(
                'message' => lang('del_succes'),
                'back_url' => route('admin.aigc.usage_log'),
            );
        }

        $msg = preg_replace('/d%/Ums', 'ID: ' . $id, lang('del_check'));
        return array(
            'message' => $msg,
            'back_url' => route('admin.aigc.usage_log'),
            'timeout' => '30',
            'confirm_url' => route('admin.aigc.usage_log.destroy', array('id' => $id)),
        );
    }

    /**
     * 列表批量操作。
     *
     * @param array $post checkbox、action 等表单字段
     * @return array{message: string, back_url: string}
     * @throws DomainException
     */
    public function action(array $post)
    {
        if (empty($post['checkbox']) || !is_array($post['checkbox'])) {
            throw new DomainException(lang('aigc_usage_log_select_empty'), route('admin.aigc.usage_log'));
        }

        $action = Arr::get($post, 'action', '');
        if ($action === 'del_all') {
            $ids = Check::intIds($post['checkbox']);
            if (empty($ids)) {
                throw new DomainException(lang('aigc_usage_log_select_empty'), route('admin.aigc.usage_log'));
            }
            AigcUsageLog::whereIn('id', $ids)->delete();
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'bulk:AigcUsageLog (' . count($ids) . ' items)', 'aigc_usage_log');
            return array('message' => lang('del_succes'), 'back_url' => route('admin.aigc.usage_log'));
        }

        throw new DomainException(lang('select_empty'), route('admin.aigc.usage_log'));
    }
}
