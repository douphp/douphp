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

namespace Dou\Admin\Controller\Manager;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Manager\ManagerFormRequest;
use Dou\Admin\Service\Manager\ManagerService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 管理员账号
 *
 * 格式校验由 ManagerFormRequest 完成；业务规则由 ManagerService 处理；
 * 权限与非法参数通过 DomainException 交由入口统一 `$context->message->respond()`。
 */
class ManagerController extends BaseController
{
    /** @var ManagerService */
    private $managerService;

    /**
     * @param ManagerService $managerService
     */
    public function __construct(ManagerService $managerService)
    {
        $this->managerService = $managerService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'manager',
        );
    }

    /**
     * @return Response
     */
    public function index()
    {
        return $this->view('manager.htm', [
            'ur_here' => lang('manager'),
            'page_actions' => array(
                array('href' => route('admin.manager.create'), 'text' => lang('manager_create'), 'style' => ''),
            ),
            'rec' => 'default',
            'manager_list' => $this->managerService->buildManagerListData(),
        ]);
    }

    /**
     * @return Response
     */
    public function create()
    {
        $admin = auth('admin')->user();
        if ($admin['action_list'] !== 'ALL') {
            throw new DomainException(lang('without'), route('admin.manager'));
        }

        return $this->view('manager.htm', [
            'ur_here' => lang('manager'),
            'page_actions' => array(
                array('href' => route('admin.manager'), 'text' => lang('manager_list'), 'style' => ''),
            ),
            'rec' => 'create',
            'manager_default' => $this->managerService->buildManagerDefaultData(),
            'action_list' => $this->managerService->adminActionList(),
        ]);
    }

    /**
     * 提交新增（字段白名单与校验见 ManagerFormRequest::rules()）。
     *
     * @param ManagerFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(ManagerFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $newId = $this->managerService->insert($data);
        return redirect(route('admin.manager.edit', array('id' => $newId)))
            ->with('success', lang('manager_add_succes'), route('admin.manager'), lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.manager'));
        }

        $managerInfo = $this->managerService->buildManagerEditData($id);
        if ($managerInfo === null) {
            throw new DomainException(lang('illegal'), route('admin.manager'));
        }

        $admin = auth('admin')->user();
        if ($admin['action_list'] !== 'ALL' && $managerInfo['username'] !== $admin['username']) {
            throw new DomainException(lang('without'), route('admin.manager'));
        }

        $ifCheck = !($admin['action_list'] === 'ALL' && $managerInfo['username'] !== $admin['username']);

        return $this->view('manager.htm', [
            'ur_here' => lang('manager'),
            'page_actions' => array(
                array('href' => route('admin.manager'), 'text' => lang('manager_list'), 'style' => ''),
            ),
            'rec' => 'edit',
            'if_check' => $ifCheck,
            'manager_info' => $managerInfo,
            'action_list' => $this->managerService->adminActionList($id),
        ]);
    }

    /**
     * 提交更新（字段白名单与校验见 ManagerFormRequest::rules()）。
     *
     * @param ManagerFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(ManagerFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $this->managerService->update($data);
        return redirect(route('admin.manager.edit', array('id' => (int) $data['id'])))
            ->with('success', lang('manager_edit_succes'), route('admin.manager'), lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.manager'));
        }

        $result = $this->managerService->delete($id, $request->post());
        return $this->respondDeleteResult($result);
    }

    /**
     * 操作日志列表（route=manager/log）。
     *
     * 不接收 POST，仅 GET 取参；非法输入塞 `$rejectFilter` 交由 Service 走
     * `where('id', -1)` 强制空集兜底（非清零原值以保留回显）。
     * 非 ALL 权限管理员的越权读 admin_id 由 Service 端再次强制锁定，控制器仅做格式校验。
     *
     * @param Request $request
     * @return Response
     */
    public function log(Request $request)
    {
        $usernameRaw = trim((string) $request->input('username'));
        $adminIdRaw = $request->integer('admin_id', 0);
        $actionRaw = trim((string) $request->input('action'));
        $moduleRaw = trim((string) $request->input('module'));
        $ipRaw = trim((string) $request->input('ip'));
        $dateStartRaw = trim((string) $request->input('date_start'));
        $dateEndRaw = trim((string) $request->input('date_end'));

        $usernameValid = $usernameRaw === '' || Check::adminAccount($usernameRaw);
        $ipValid = $ipRaw === '' || !Check::illegalChar($ipRaw);
        $dateStartValid = $dateStartRaw === '' || $this->isDate($dateStartRaw);
        $dateEndValid = $dateEndRaw === '' || $this->isDate($dateEndRaw);

        $username = $usernameValid ? $usernameRaw : '';
        $ip = $ipValid ? $ipRaw : '';
        $dateStart = $dateStartValid ? $dateStartRaw : '';
        $dateEnd = $dateEndValid ? $dateEndRaw : '';
        $action = in_array($actionRaw, $this->managerService->actionOptions(), true) ? $actionRaw : '';

        $allowedModules = array();
        foreach ($this->managerService->moduleOptions() as $opt) {
            $allowedModules[] = $opt['value'];
        }
        $module = in_array($moduleRaw, $allowedModules, true) ? $moduleRaw : '';

        $rejectFilter = array(
            'username' => !$usernameValid,
            'ip' => !$ipValid,
            'date_start' => !$dateStartValid,
            'date_end' => !$dateEndValid,
        );

        $pageUrl = route('admin.manager.log');
        if ($adminIdRaw > 0) {
            $pageUrl .= '&admin_id=' . $adminIdRaw;
        }
        if ($usernameRaw !== '') {
            $pageUrl .= '&username=' . rawurlencode($usernameRaw);
        }
        if ($actionRaw !== '') {
            $pageUrl .= '&action=' . rawurlencode($actionRaw);
        }
        if ($moduleRaw !== '') {
            $pageUrl .= '&module=' . rawurlencode($moduleRaw);
        }
        if ($ipRaw !== '') {
            $pageUrl .= '&ip=' . rawurlencode($ipRaw);
        }
        if ($dateStartRaw !== '') {
            $pageUrl .= '&date_start=' . rawurlencode($dateStartRaw);
        }
        if ($dateEndRaw !== '') {
            $pageUrl .= '&date_end=' . rawurlencode($dateEndRaw);
        }

        $page = $request->integer('page', 1);

        $data = $this->managerService->buildManagerLogData(
            $username,
            $adminIdRaw,
            $action,
            $module,
            $ip,
            $dateStart,
            $dateEnd,
            $pageUrl,
            $page,
            $rejectFilter
        );

        $logActionList = array();
        foreach ($this->managerService->actionOptions() as $value) {
            $logActionList[] = array(
                'value' => $value,
                'label' => AdminLogAction::label($value),
                'cur' => $value === $action,
            );
        }

        $logModuleList = array();
        foreach ($this->managerService->moduleOptions() as $opt) {
            $logModuleList[] = array(
                'value' => $opt['value'],
                'label' => $opt['label'],
                'cur' => $opt['value'] === $module,
            );
        }

        return $this->view('manager.htm', [
            'ur_here' => lang('manager_log'),
            'cur' => 'log',
            'rec' => 'log',
            'req' => array(
                'username' => $usernameRaw,
                'admin_id' => $adminIdRaw,
                'action' => $actionRaw,
                'module' => $moduleRaw,
                'ip' => $ipRaw,
                'date_start' => $dateStartRaw,
                'date_end' => $dateEndRaw,
            ),
            'log_action_list' => $logActionList,
            'log_module_list' => $logModuleList,
            'log_list' => $data['log_list'],
            'pager' => $data['pager'],
        ]);
    }

    /**
     * 验证 YYYY-MM-DD 形式的日期字符串。
     *
     * @param string $value
     * @return bool
     */
    private function isDate($value)
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
