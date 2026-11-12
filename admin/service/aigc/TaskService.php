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

use Dou\Admin\Model\Ai\AiProvider;
use Dou\Admin\Model\Aigc\AigcUsageLog;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Ai\AiGateway;
use Dou\Core\Service\Ai\Task\AsyncTaskRepository;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员 AI 异步任务服务：列表、删除（aigc_task 表）。
 *
 * aigc_task 与后台 ai_task 结构一致但归属分离：会员端任务落
 * aigc_task（user_id + app_id），后台任务落 ai_task（admin_id + app_id）。
 */
class TaskService extends BaseService
{
    /** @var AiGateway */
    private $gateway;

    /** @var AsyncTaskRepository */
    private $repository;

    /**
     * @param AiGateway $gateway
     */
    public function __construct(AiGateway $gateway)
    {
        $this->gateway = $gateway;
        $this->repository = new AsyncTaskRepository(AsyncTaskRepository::AIGC_TABLE);
    }

    /**
     * 任务列表页数据。
     *
     * @param array $req status/task_type/page
     * @return array {task_list, model_names, provider_names, pager, req}
     */
    public function getIndexPageData(array $req)
    {
        $status = isset($req['status']) ? trim((string) $req['status']) : '';
        $taskType = isset($req['task_type']) ? trim((string) $req['task_type']) : '';
        $page = isset($req['page']) ? (int) $req['page'] : 1;

        $pageUrl = route('admin.aigc.task');
        if ($status !== '') {
            $pageUrl .= '&status=' . rawurlencode($status);
        }
        if ($taskType !== '') {
            $pageUrl .= '&task_type=' . rawurlencode($taskType);
        }

        $result = $this->repository->paginate(array(
            'status' => $status,
            'task_type' => $taskType,
        ), $page, $pageUrl);

        $modelIds = array();
        $providerIds = array();
        $userIds = array();
        $appIds = array();
        foreach ($result['list'] as $row) {
            $modelIds[] = (int) $row['model_id'];
            $providerIds[] = (int) $row['provider_id'];
            $userIds[] = (int) $row['user_id'];
            $appIds[] = (int) $row['app_id'];
        }
        $appNames = $this->appNames($appIds);

        // 模板引擎（DouView）不允许 {if isset(...)}，故按本页任务实际 id 补齐映射：
        // 供应商/模型被删除的旧任务对应位填 '--'，模板可直接输出无需存在性判断
        $modelNames = array();
        foreach (array_unique($modelIds) as $id) {
            $modelNames[$id] = '--';
        }
        foreach ($this->gateway->getModelNames($modelIds) as $id => $name) {
            $modelNames[(int) $id] = $name;
        }

        $providerNames = array();
        foreach (array_unique($providerIds) as $id) {
            $providerNames[$id] = '--';
        }
        foreach ($this->providerNames($providerIds) as $id => $name) {
            $providerNames[(int) $id] = $name;
        }

        // 会员信息映射（头像 + 昵称）
        $users = array();
        foreach (array_unique($userIds) as $id) {
            $users[$id] = false;
        }
        if (user() !== null) {
            foreach ($users as $id => $none) {
                $info = ((int) $id > 0) ? user()->format($id) : false;
                if ($info !== false) {
                    $users[(int) $id] = $info;
                }
            }
        }

        $taskList = array();
        foreach ($result['list'] as $row) {
            $row['user'] = $users[(int) $row['user_id']];
            $row['app_name'] = isset($appNames[(int) $row['app_id']]) ? $appNames[(int) $row['app_id']] : '-';
            $row['task_type_format'] = $this->labelOrRaw('aigc_task_type_', $row['task_type']);
            $row['status_format'] = $this->labelOrRaw('aigc_task_status_', $row['status']);
            $row['result_summary'] = $this->resultSummary($row);
            $taskList[] = $row;
        }

        return array(
            'task_list' => $taskList,
            'model_names' => $modelNames,
            'provider_names' => $providerNames,
            'pager' => $result['pager'],
            'req' => $req,
        );
    }

    /**
     * 任务详情数据。
     *
     * @param mixed $id
     * @return array|null task
     */
    public function getViewBundle($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $task = $this->repository->find($id);
        if (!$task) {
            return null;
        }

        $task['app_name'] = (int) $task['app_id'] > 0
            ? (string) DB::table('aigc')->where('id', (int) $task['app_id'])->value('name')
            : '-';
        if ($task['app_name'] === '') {
            $task['app_name'] = '-';
        }
        $task['task_type_format'] = $this->labelOrRaw('aigc_task_type_', $task['task_type']);
        $task['status_format'] = $this->labelOrRaw('aigc_task_status_', $task['status']);
        $task['provider_name'] = $task['provider_id'] ? AigcUsageLog::getProviderName($task['provider_id']) : '-';
        $task['model_name'] = $task['model_id'] ? AigcUsageLog::getModelName($task['model_id']) : '-';
        $task['user'] = ((int) $task['user_id'] > 0 && user() !== null) ? user()->format($task['user_id']) : false;
        $task['request_payload_formatted'] = $this->prettyJson(isset($task['request_payload']) ? $task['request_payload'] : '');
        $task['result_formatted'] = $this->prettyJson(isset($task['result']) ? $task['result'] : '');
        $task['created_at'] = !empty($task['created_at']) ? date('Y-m-d H:i:s', strtotime($task['created_at'])) : '';
        $task['updated_at'] = !empty($task['updated_at']) ? date('Y-m-d H:i:s', strtotime($task['updated_at'])) : '';

        return array('task' => $task);
    }

    /**
     * JSON 美化；非 JSON 原样返回。
     *
     * @param string $raw
     * @return string
     */
    private function prettyJson($raw)
    {
        $raw = trim((string) $raw);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $raw;
        }

        return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 删除任务（终态任务可删）。
     *
     * @param int $id
     * @param array $post
     * @return array {message, back_url}
     */
    public function delete($id, array $post)
    {
        $id = (int) $id;
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.aigc.task'));
        }

        $task = $this->repository->find($id);
        if (!$task) {
            throw new DomainException(lang('illegal'), route('admin.aigc.task'));
        }

        // 仅终态任务可删；pending/running 删除会丢失执行结果与巡检线索
        $status = isset($task['status']) ? (string) $task['status'] : '';
        if (!in_array($status, array('succeeded', 'failed', 'timeout'), true)) {
            throw new DomainException(lang('aigc_task_not_terminal'), route('admin.aigc.task'));
        }

        if (isset($post['confirm'])) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'ID:' . $id, 'aigc_task');
            $this->repository->delete($id);

            return array(
                'message' => lang('del_succes'),
                'back_url' => route('admin.aigc.task'),
            );
        }

        $msg = preg_replace('/d%/Ums', (string) $id, lang('del_check'));

        return array(
            'message' => $msg,
            'back_url' => route('admin.aigc.task'),
            'timeout' => '30',
            'confirm_url' => route('admin.aigc.task.destroy', array('id' => $id)),
        );
    }

    /**
     * 应用名映射（id => name）。
     *
     * @param array $appIds
     * @return array
     */
    private function appNames(array $appIds)
    {
        $appIds = array_values(array_filter(array_map('intval', $appIds)));
        if (!$appIds) {
            return array();
        }

        $map = array();
        $rows = (array) DB::table('aigc')->field('id, name')->whereIn('id', $appIds)->select();
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row['name'];
        }

        return $map;
    }

    /**
     * 语言键取标签，缺失时回退原始值。
     *
     * @param string $prefix
     * @param string $value
     * @return string
     */
    private function labelOrRaw($prefix, $value)
    {
        $value = (string) $value;
        $key = $prefix . $value;

        return lang_has($key) ? lang($key) : $value;
    }

    /**
     * 任务结果摘要：提取可读标题 / URL，无结果时返回占位。
     *
     * @param array $row
     * @return string
     */
    private function resultSummary(array $row)
    {
        $result = isset($row['result']) ? trim((string) $row['result']) : '';
        if ($result === '' || $result === '[]' || $result === '{}') {
            return '-';
        }

        $decoded = json_decode($result, true);
        if (is_array($decoded)) {
            foreach (array('url', 'file', 'title', 'text', 'message') as $key) {
                if (isset($decoded[$key]) && is_scalar($decoded[$key]) && trim((string) $decoded[$key]) !== '') {
                    return $this->truncate((string) $decoded[$key], 60);
                }
            }
        }

        return $this->truncate($result, 60);
    }

    /**
     * 多字节安全截断。
     *
     * @param string $text
     * @param int $limit
     * @return string
     */
    private function truncate($text, $limit)
    {
        if (function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') . '…' : $text;
        }

        return strlen($text) > $limit ? substr($text, 0, $limit) . '…' : $text;
    }

    /**
     * 供应商名映射（id => name）。
     *
     * @param array $providerIds
     * @return array
     */
    private function providerNames(array $providerIds)
    {
        $providerIds = array_values(array_filter(array_map('intval', $providerIds)));
        if (!$providerIds) {
            return array();
        }

        $names = array();
        $rows = AiProvider::whereIn('id', $providerIds)->get();
        foreach ($rows as $row) {
            $attrs = $row->getAttributes();
            $names[(int) $attrs['id']] = $attrs['name'];
        }

        return $names;
    }
}
