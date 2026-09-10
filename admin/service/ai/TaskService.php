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

use Dou\Admin\Model\Ai\AiApplication;
use Dou\Admin\Model\Ai\AiProvider;
use Dou\Admin\Service\Ai\Prompt\PromptComposer;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Ai\AiGateway;
use Dou\Core\Service\Ai\Task\AsyncTaskRepository;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 异步任务服务：提交、列表、删除。
 *
 * 提交链路：应用（ai_application）→ 模型 → AiGateway::submitAsyncTask；
 * 轮询由 TaskController::show 直接调 AiGateway::pollAsyncTask（本类不参与）。
 */
class TaskService extends BaseService
{
    /** @var array 提交参数白名单（prompt 之外允许透传的驱动参数） */
    const PARAM_WHITELIST = array('size', 'width', 'height', 'duration', 'resolution');

    /** @var AiGateway */
    private $gateway;

    /** @var AsyncTaskRepository */
    private $repository;

    /** @var PromptComposer */
    private $composer;

    /**
     * @param AiGateway $gateway
     * @param PromptComposer $composer
     */
    public function __construct(AiGateway $gateway, PromptComposer $composer)
    {
        $this->gateway = $gateway;
        $this->composer = $composer;
        $this->repository = new AsyncTaskRepository();
    }

    /**
     * 按应用提交异步任务。
     *
     * @param int $appId 应用 ID
     * @param string $prompt 提示词
     * @param int $adminId
     * @param array $post 请求 POST（白名单键透传给驱动）
     * @return array {task_id, status}
     */
    public function submit($appId, $prompt, $adminId, array $post)
    {
        $app = AiApplication::find((int) $appId);
        if (!$app || (int) $app['status'] !== 1) {
            throw new DomainException(lang('ai_generate_app_not_found'));
        }

        $prompt = trim((string) $prompt);
        if ($prompt === '') {
            $placement = isset($app['placement']) ? (string) $app['placement'] : 'fill';
            $prompt = $this->composer->instruction($placement);
        }

        $params = array('prompt' => $prompt);
        foreach (self::PARAM_WHITELIST as $key) {
            if (isset($post[$key]) && $post[$key] !== '') {
                $params[$key] = $post[$key];
            }
        }

        $modelId = isset($app['model_id']) && (int) $app['model_id'] > 0 ? (int) $app['model_id'] : null;
        $result = $this->gateway->submitAsyncTask($modelId, $params, array(
            'app_id' => (int) $app['id'],
            'admin_id' => (int) $adminId,
            'task_type' => isset($app['task_type']) ? (string) $app['task_type'] : '',
        ));

        if (!$result['success']) {
            throw new DomainException(lang('ai_generate_failed') . ': ' . $result['error']);
        }

        return array(
            'task_id' => $result['task_id'],
            'status' => $result['status'],
        );
    }

    /**
     * 任务列表页数据。
     *
     * @param array $req status/task_type/page
     * @return array {task_list, model_names, provider_names, admin_names, pager, req}
     */
    public function getIndexPageData(array $req)
    {
        $status = isset($req['status']) ? trim((string) $req['status']) : '';
        $taskType = isset($req['task_type']) ? trim((string) $req['task_type']) : '';
        $page = isset($req['page']) ? (int) $req['page'] : 1;

        $pageUrl = route('admin.ai.task');
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
        $adminIds = array();
        foreach ($result['list'] as $row) {
            $modelIds[] = (int) $row['model_id'];
            $providerIds[] = (int) $row['provider_id'];
            $adminIds[] = (int) $row['admin_id'];
        }

        // 模板引擎（DouView）不允许 {if isset(...)}，故按本页任务实际 id 补齐映射：
        // 供应商/模型/管理员被删除的旧任务对应位填 '--'，模板可直接输出无需存在性判断
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

        $adminNames = array();
        foreach (array_unique($adminIds) as $id) {
            $adminNames[$id] = '--';
        }
        foreach ($this->adminNames($adminIds) as $id => $name) {
            $adminNames[(int) $id] = $name;
        }

        return array(
            'task_list' => $result['list'],
            'model_names' => $modelNames,
            'provider_names' => $providerNames,
            'admin_names' => $adminNames,
            'pager' => $result['pager'],
            'req' => $req,
        );
    }

    /**
     * 取任务行（数组形态，供控制器补记用量等场景）。
     *
     * @param int $id
     * @return array|null
     */
    public function findTask($id)
    {
        return $this->repository->find((int) $id);
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
            throw new DomainException(lang('illegal'), route('admin.ai.task'));
        }

        $task = $this->repository->find($id);
        if (!$task) {
            throw new DomainException(lang('illegal'), route('admin.ai.task'));
        }

        if (isset($post['confirm'])) {
            $this->repository->delete($id);

            return array(
                'message' => lang('del_succes'),
                'back_url' => route('admin.ai.task'),
            );
        }

        $msg = preg_replace('/d%/Ums', (string) $id, lang('del_check'));

        return array(
            'message' => $msg,
            'back_url' => route('admin.ai.task'),
            'timeout' => '30',
            'confirm_url' => route('admin.ai.task.destroy', array('id' => $id)),
        );
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

    /**
     * 管理员名映射（id => username）。
     *
     * @param array $adminIds
     * @return array
     */
    private function adminNames(array $adminIds)
    {
        $adminIds = array_values(array_filter(array_map('intval', $adminIds)));
        if (!$adminIds) {
            return array();
        }

        $names = array();
        foreach (DB::table('admin')->where('id', 'IN', $adminIds)->select() as $row) {
            $names[(int) $row['id']] = $row['username'];
        }

        return $names;
    }
}
