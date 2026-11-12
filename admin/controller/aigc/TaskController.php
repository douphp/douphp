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

namespace Dou\Admin\Controller\Aigc;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Service\Aigc\TaskService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员 AI 异步任务（route=aigc/task/...）：
 * - index：任务列表页（aigc_task 表，会员端任务）
 * - show：任务详情（请求参数 / 结果 / 错误）
 * - destroy：删除任务
 */
class TaskController extends BaseController
{
    /** @var TaskService */
    private $taskService;

    /**
     * @param TaskService $taskService
     */
    public function __construct(TaskService $taskService)
    {
        $this->taskService = $taskService;
    }

    /**
     * 任务列表页（route=aigc/task/index）。
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $req = array(
            'status' => trim((string) $request->input('status', '')),
            'task_type' => trim((string) $request->input('task_type', '')),
            'page' => $request->integer('page', 1),
        );

        $bundle = $this->taskService->getIndexPageData($req);

        return $this->view('aigc_task.htm', array(
            'ur_here' => lang('aigc_task'),
            'rec' => 'default',
            'req' => $req,
            'task_list' => $bundle['task_list'],
            'model_names' => $bundle['model_names'],
            'provider_names' => $bundle['provider_names'],
            'pager' => $bundle['pager'],
        ));
    }

    /**
     * 任务详情（route=aigc/task/show）。
     *
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $bundle = $this->taskService->getViewBundle($request->integer('id', 0));
        if (!$bundle) {
            throw new DomainException(lang('illegal'), route('admin.aigc.task'));
        }

        return $this->view('aigc_task.htm', array(
            'ur_here' => lang('aigc_task_show'),
            'page_actions' => array(
                array('href' => route('admin.aigc.task'), 'text' => lang('aigc_task'), 'style' => ''),
            ),
            'rec' => 'show',
            'task' => $bundle['task'],
        ));
    }

    /**
     * 删除任务（route=aigc/task/del）。
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.aigc.task'));
        }

        $result = $this->taskService->delete($id, $request->post());

        return $this->respondDeleteResult($result);
    }
}
