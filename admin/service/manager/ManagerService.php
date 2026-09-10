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

namespace Dou\Admin\Service\Manager;

use Dou\Admin\Model\Manager\Manager;
use Dou\Admin\Model\Manager\ManagerAdminLog;
use Dou\Admin\Service\Menu\AdminMenuService;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Admin\AdminLogDetail;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 管理员账号维护
 *
 * `insert`/`update`/`delete` 与控制器同名；列表/编辑/日志数据用 `build*Data`；
 * 输入格式与唯一性由 ManagerFormRequest 承担；权限与业务阻断在此抛 DomainException。
 */
class ManagerService extends BaseService
{
    /** @var AdminMenuService */
    private $menuService;

    /**
     * @param AdminMenuService $menuService 框架基础菜单元数据
     */
    public function __construct(AdminMenuService $menuService)
    {
        $this->menuService = $menuService;
    }

    /**
     * 新增页默认数据。
     *
     * @return array
     */
    public function buildManagerDefaultData()
    {
        return array(
            'username' => '',
            'email' => '',
        );
    }

    /**
     * 编辑表单用数据
     *
     * @param int $id
     * @return array|null
     */
    public function buildManagerEditData($id)
    {
        $id = (int) $id;
        if ($id < 1) {
            return null;
        }

        $rowModel = Manager::find($id);
        $row = $rowModel ? $rowModel->getAttributes() : null;
        if (!$row || !is_array($row)) {
            return null;
        }

        return $row;
    }

    /**
     * 列表用数据
     *
     * @return array
     */
    public function buildManagerListData()
    {
        $managerList = array();
        foreach (Manager::getAllOrdered() as $row) {
            if ($row['action_list'] === 'ALL' || $row['action_list'] === 'ADMIN') {
                $type = lang('manager_type_' . strtolower($row['action_list']));
            } else {
                $type = lang('manager_type_defined');
            }
            $managerList[] = array(
                'admin_id' => $row['id'],
                'username' => $row['username'],
                'email' => $row['email'],
                'type' => $type,
                'created_at' => date('Y-m-d', strtotime($row['created_at'])),
                'last_login' => date('Y-m-d H:i:s', $row['last_login']),
            );
        }

        return $managerList;
    }

    /**
     * 处理新增提交（权限用上下文中的 admin）。
     *
     * 字段白名单与校验见 ManagerFormRequest::rules()；token 在 Controller 校验。
     *
     * @param array $data validated 数据
     * @return int 新增记录主键
     */
    public function insert(array $data)
    {
        $admin = auth('admin')->user();
        if ($admin['action_list'] !== 'ALL') {
            throw new DomainException(lang('without'), route('admin.manager'));
        }

        $username = isset($data['username']) ? trim((string) $data['username']) : '';
        $email = isset($data['email']) ? trim((string) $data['email']) : '';
        $password = isset($data['password']) ? $data['password'] : '';
        $action = isset($data['action']) ? $data['action'] : '';

        if ($action === 'DEFINED') {
            $actionListArray = isset($data['action_list'])
                ? (is_array($data['action_list']) ? $data['action_list'] : array($data['action_list']))
                : array();
            if (empty($actionListArray)) {
                throw new DomainException(lang('manager_action_list_empty'), route('admin.manager'));
            }
            $actionList = implode(',', $actionListArray);
        } else {
            $actionList = $action;
        }

        $model = Manager::create(array(
            'username' => $username,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'action_list' => $actionList,
            'created_at' => date('Y-m-d H:i:s'),
        ));
        $newId = (int) $model->getKey();

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CREATE, 1, $username);

        return $newId;
    }

    /**
     * 处理更新提交。
     *
     * @param array $data validated 数据
     * @return void
     */
    public function update(array $data)
    {
        $admin = auth('admin')->user();
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        if ($id < 1) {
            throw new DomainException(lang('illegal'), route('admin.manager'));
        }

        $managerInfoModel = Manager::find($id);
        $managerInfo = $managerInfoModel ? $managerInfoModel->getAttributes() : null;
        if (!$managerInfo || !is_array($managerInfo)) {
            throw new DomainException(lang('illegal'), route('admin.manager'));
        }

        if ($admin['action_list'] !== 'ALL' && $managerInfo['username'] !== $admin['username']) {
            throw new DomainException(lang('without'), route('admin.manager'));
        }

        $username = isset($data['username']) ? trim((string) $data['username']) : '';
        $email = isset($data['email']) ? trim((string) $data['email']) : '';
        $oldPassword = Arr::get($data, 'old_password', '');
        $newPwdInput = isset($data['password']) ? $data['password'] : '';
        $postAction = isset($data['action']) ? $data['action'] : '';

        if (!($admin['action_list'] === 'ALL' && $managerInfo['username'] !== $admin['username'])) {
            if ($oldPassword === '') {
                throw new DomainException(lang('manager_old_password_cue'), route('admin.manager'));
            }

            $storedPwd = $managerInfo['password'];
            if (strlen($storedPwd) === 32 && ctype_xdigit($storedPwd)) {
                $oldValid = (md5($oldPassword) === $storedPwd);
                if ($oldValid) {
                    Manager::whereKey($id)->update(array('password' => password_hash($oldPassword, PASSWORD_BCRYPT)));
                }
            } else {
                $oldValid = password_verify($oldPassword, $storedPwd);
            }

            if (!isset($oldValid) || !$oldValid) {
                audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 0, AdminLogDetail::OLD_PASSWORD_WRONG);
                throw new DomainException(lang('manager_old_password_cue'), route('admin.manager'));
            }
        }

        $newPassword = '';
        if ($newPwdInput !== '') {
            $newPassword = password_hash($newPwdInput, PASSWORD_BCRYPT);
        }

        $newActionList = '';
        if ($postAction !== '') {
            if ($postAction === 'DEFINED') {
                $actionListArray = isset($data['action_list'])
                    ? (is_array($data['action_list']) ? $data['action_list'] : array($data['action_list']))
                    : array();
                if (empty($actionListArray)) {
                    throw new DomainException(lang('manager_action_list_empty'), route('admin.manager'));
                }
                $newActionList = implode(',', $actionListArray);
            } else {
                $newActionList = $postAction;
            }
        }

        $updateData = array(
            'username' => $username,
            'email' => $email,
        );
        if ($newPassword !== '') {
            $updateData['password'] = $newPassword;
        }
        if ($newActionList !== '') {
            $updateData['action_list'] = $newActionList;
        }
        Manager::whereKey($id)->update($updateData);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, $username);
    }

    /**
     * 删除管理员（二次确认或执行删除）。
     *
     * @param int $adminId 被删管理员 id
     * @param array $post POST 数据
     * @return array{message: string, back_url: string, timeout: string, confirm_url: string}
     * @throws DomainException 需确认时抛出
     */
    public function delete($adminId, array $post)
    {
        $admin = auth('admin')->user();
        if ($admin['action_list'] !== 'ALL') {
            throw new DomainException(lang('without'), route('admin.manager'));
        }

        $adminId = (int) $adminId;
        $managerRowModel = Manager::find($adminId);
        $managerRow = $managerRowModel ? $managerRowModel->getAttributes() : null;
        if (!$managerRow || !is_array($managerRow)) {
            throw new DomainException(lang('illegal'), route('admin.manager'));
        }

        $username = isset($managerRow['username']) ? (string) $managerRow['username'] : '';

        if ($username === $admin['username']) {
            throw new DomainException(lang('manager_del_wrong'), route('admin.manager'), '3');
        }

        if (isset($post['confirm'])) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, $username);
            Manager::destroy($adminId);

            return array(
                'message' => lang('manager_del_succes'),
                'back_url' => route('admin.manager'),
            );
        }

        $delCheck = preg_replace('/d%/Ums', $username, lang('del_check'));
        return array(
            'message' => $delCheck,
            'back_url' => route('admin.manager'),
            'timeout' => '30',
            'confirm_url' => route('admin.manager.destroy', array('id' => $adminId)),
        );
    }

    /**
     * action 下拉候选（供模板渲染 filter 与控制器白名单校验）。
     *
     * 字典源 = {@see AdminLogAction::all()}；模板侧通过 {@see AdminLogAction::label()}
     * 把方括号字面量翻译为中文显示，value 仍保留字典常量供 SQL where 精确匹配。
     *
     * @return array<int, string>
     */
    public function actionOptions()
    {
        return AdminLogAction::all();
    }

    /**
     * module 下拉候选（供模板渲染 filter 与控制器白名单校验）。
     *
     * 候选源 = `module.column_module` + `module.single_module` + {@see AdminMenuService::basicMenu()}，
     * 即与 {@see AuditService::writeAdminLog} 写入时 `Request::routeModule()` 实际取值集合
     * 最接近的口径。返回的是模块短名（不含语言串），模板侧调 `lang($module)` 翻译为中文。
     *
     * 去重 + 自然顺序，与 {@see adminActionList} 拼"权限勾选"用的口径一致；该方法只产出
     * "模块短名 → 中文 label"的 plain options 结构，不附带勾选状态。
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function moduleOptions()
    {
        $names = array();

        foreach ((array) Config::get('module.column_module') as $value) {
            if (!in_array($value, (array) Config::get('module.no_show_menu'))) {
                $names[$value] = true;
            }
        }

        foreach ((array) Config::get('module.single_module') as $value) {
            if (in_array($value, (array) Config::get('module.no_show_menu'))) {
                continue;
            }
            if (in_array($value, (array) Config::get('system.admin_hidden_single', array()), true)) {
                continue;
            }
            $names[$value] = true;
        }

        $basicMenu = $this->menuService->basicMenu();
        if (!Config::get('site.close_douphp_plus', false)) {
            $basicMenu[] = 'module';
        }
        if (!Config::get('site.close_miniprogram', false)) {
            $basicMenu[] = 'miniprogram';
        }
        if (!Config::get('site.pure_mode', false)) {
            $basicMenu[] = 'theme';
        }
        foreach ($basicMenu as $value) {
            $names[(string) $value] = true;
        }

        ksort($names);

        $options = array();
        foreach (array_keys($names) as $name) {
            $label = lang($name);
            $options[] = array(
                'value' => $name,
                'label' => $label !== '' ? $label : $name,
            );
        }
        return $options;
    }

    /**
     * 操作日志列表数据（与 {@see \Dou\Admin\Service\User\LogService::buildUserLogListData}
     * 命名/形状一致，多一个 admin_log 特有的 `module` 维度）。
     *
     * 权限边界：非 ALL 权限管理员无视入参 `$username` / `$adminId`，由本方法强制锁定回
     * 当前登录管理员；即使前端用 query string 显式塞别人的 admin_id 也不会越权读到。
     *
     * @param string $username 管理员账号关键字（精确匹配 admin.username）
     * @param int $adminId 指定管理员（与 username 二选一，admin_id 优先）
     * @param string $action 操作类型，须在 {@see actionOptions()} 内
     * @param string $module 路由模块短名，须在 {@see moduleOptions()} 内
     * @param string $ip 操作 IP（精确匹配）
     * @param string $dateStart YYYY-MM-DD（包含当日 0 点）
     * @param string $dateEnd YYYY-MM-DD（包含当日 23:59:59）
     * @param string $pageUrl 分页 URL（由控制器按原始查询参数拼装）
     * @param int $page
     * @param array $rejectFilter 有输入但未通过对应校验时为 true：`username` / `ip` / `date_start` / `date_end`
     * @return array array('log_list' => array, 'pager' => mixed)
     */
    public function buildManagerLogData($username, $adminId, $action, $module, $ip, $dateStart, $dateEnd, $pageUrl, $page = 1, array $rejectFilter = array())
    {
        $admin = auth('admin')->user();
        $isAll = ($admin['action_list'] === 'ALL');
        $lockedAdminId = $isAll ? 0 : (int) $admin['admin_id'];

        $filter = array(
            'admin_id' => null,
            'action' => '',
            'module' => '',
            'ip' => '',
            'date_start_ts' => null,
            'date_end_ts' => null,
            'force_empty' => false,
        );

        $forceEmpty = !empty($rejectFilter['username'])
            || !empty($rejectFilter['ip'])
            || !empty($rejectFilter['date_start'])
            || !empty($rejectFilter['date_end']);

        if ($forceEmpty) {
            $filter['force_empty'] = true;
        } else {
            $resolvedAdminId = (int) $adminId;
            if ($resolvedAdminId <= 0 && $username !== '') {
                $row = Manager::findByUsername($username);
                $resolvedAdminId = ($row && isset($row['id'])) ? (int) $row['id'] : -1;
            }

            if (!$isAll) {
                if ($resolvedAdminId > 0 && $resolvedAdminId !== $lockedAdminId) {
                    $filter['admin_id'] = -1;
                } else {
                    $filter['admin_id'] = $lockedAdminId;
                }
            } elseif ($resolvedAdminId > 0) {
                $filter['admin_id'] = $resolvedAdminId;
            } elseif ($resolvedAdminId === -1) {
                $filter['admin_id'] = -1;
            }

            if ($action !== '' && in_array($action, AdminLogAction::all(), true)) {
                $filter['action'] = $action;
            }

            if ($module !== '') {
                $allowed = array();
                foreach ($this->moduleOptions() as $opt) {
                    $allowed[] = $opt['value'];
                }
                if (in_array($module, $allowed, true)) {
                    $filter['module'] = $module;
                }
            }

            if ($ip !== '') {
                $filter['ip'] = $ip;
            }

            $filter['date_start_ts'] = $this->parseDateStart($dateStart);
            $filter['date_end_ts'] = $this->parseDateEnd($dateEnd);
        }

        $query = ManagerAdminLog::query();
        if (!empty($filter['force_empty'])) {
            $query->applyForceEmpty();
        } else {
            $query->filterByAdminId($filter['admin_id'])
                ->filterByAction($filter['action'])
                ->filterByModule($filter['module'])
                ->filterByIp($filter['ip'])
                ->filterByDateStartTs($filter['date_start_ts'])
                ->filterByDateEndTs($filter['date_end_ts']);
        }
        $result = $query->applyDefaultOrder()
            ->paginate(15, $page, Util::normalizeQueryString($pageUrl));

        $logList = array();
        foreach ($result['list'] as $model) {
            $logList[] = self::renderAdminLogRow($model->toArray());
        }

        return array('log_list' => $logList, 'pager' => $result['pager']);
    }

    /**
     * 解析「起始日期」为 0 点时间戳；空串或格式非法返回 null。
     *
     * @param string $value
     * @return int|null
     */
    private function parseDateStart($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value . ' 00:00:00');
        return $ts === false ? null : (int) $ts;
    }

    /**
     * 解析「结束日期」为 23:59:59 时间戳；空串或格式非法返回 null。
     *
     * @param string $value
     * @return int|null
     */
    private function parseDateEnd($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value . ' 23:59:59');
        return $ts === false ? null : (int) $ts;
    }

    /**
     * admin_log 行渲染（单一字典源）。
     *
     * 业务对象 details 与 AdminLogDetail 离散标签共存于同一列；action 字典常量
     * 经 {@see AdminLogAction::label()} 翻译为 UI 文本，未命中字典的 action 原样返回。
     * module 经语言包翻译为中文模块名，未命中时原样返回（如自定义模块）。
     *
     * 同时被 {@see ManagerService::buildManagerLogData} 与
     * {@see \Dou\Admin\Service\Index\IndexService::getAdminLog} 共用，保证后台
     * manager/log 列表与首页最近日志显示完全一致。
     *
     * @param array $row dou_admin_log 行（含 id/admin_id/action/module/ip/created_at/result/details）
     * @return array Smarty 模板可直接消费的结构
     */
    public static function renderAdminLogRow(array $row)
    {
        $module = isset($row['module']) ? (string) $row['module'] : '';
        $action = isset($row['action']) ? (string) $row['action'] : '';
        $result = isset($row['result']) ? (int) $row['result'] : 0;
        $details = isset($row['details']) ? (string) $row['details'] : '';

        $moduleLabel = $module !== '' ? lang($module) : '';
        if ($moduleLabel === '') {
            $moduleLabel = $module;
        }

        return array(
            'id' => isset($row['id']) ? $row['id'] : 0,
            'created_at' => isset($row['created_at']) && ($createdAtTs = Util::toTimestamp($row['created_at'])) !== null ? date('Y-m-d H:i:s', $createdAtTs) : '',
            'username' => DB::table('admin')->where('id', (int) $row['admin_id'])->value('username'),
            'module' => $moduleLabel,
            'module_raw' => $module,
            'action' => AdminLogAction::label($action),
            'action_raw' => $action,
            'result' => $result,
            'result_text' => $result ? lang('admin_log_result_success') : lang('admin_log_result_fail'),
            'details' => $details,
            'ip' => isset($row['ip']) ? $row['ip'] : '',
        );
    }

    /**
     * 管理员权限勾选列表（供新增/编辑管理员页使用）
     *
     * @param string $admin_id
     * @return array
     */
    public function adminActionList($admin_id = '')
    {
        $user_action_list = array();
        $action_list = array();

        if ($admin_id) {
            $user_action_list = DB::table('admin')->where('id', $admin_id)->value('action_list');
            $user_action_list = explode(',', $user_action_list);
        }

        foreach ((array) Config::get('module.column_module') as $value) {
            if (!in_array($value, (array) Config::get('module.no_show_menu'))) {
                $action_list[] = array(
                    'value' => $value . '_category',
                    'name' => lang($value . '_category'),
                    'cur' => in_array($value . '_category', $user_action_list),
                );
                $action_list[] = array(
                    'value' => $value,
                    'name' => lang($value),
                    'cur' => in_array($value, $user_action_list),
                );
            }
        }

        foreach ((array) Config::get('module.single_module') as $value) {
            if (!in_array($value, (array) Config::get('module.no_show_menu')) && !in_array($value, (array) Config::get('system.admin_hidden_single', array()), true)) {
                $action_list[] = array(
                    'value' => $value,
                    'name' => lang($value),
                    'cur' => in_array($value, $user_action_list),
                );
            }
        }

        $basic_menu = $this->menuService->basicMenu();
        if (!Config::get('site.close_douphp_plus', false)) {
            $basic_menu[] = 'module';
        }
        if (!Config::get('site.close_miniprogram', false)) {
            $basic_menu[] = 'miniprogram';
        }
        if (!Config::get('site.close_douphp_plus', false)) {
            $basic_menu[] = 'module';
        }
        if (!Config::get('site.pure_mode', false)) {
            $basic_menu[] = 'theme';
        }

        foreach ($basic_menu as $value) {
            $action_list[] = array(
                'value' => $value,
                'name' => lang($value),
                'cur' => in_array($value, $user_action_list),
            );
        }

        return $action_list;
    }
}
