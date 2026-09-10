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

namespace Dou\Core\Service\Audit;

use Dou\Core\Facade\DB;
use Dou\Core\Facade\Session;
use Dou\Core\Service\BaseService;
use Dou\Core\Web\Http\Request;
use InvalidArgumentException;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 审计日志写入服务（user_log / book_log / admin_log）。
 *
 * 设计模型：
 * - 审计 ip 由 shell 边界（{@see \Dou\Core\Init\InitTrait} 在 Request 绑定之后）通过构造函数
 *   传入，存为不可变实例属性；业务调用方调 writeXxxLog(...) 时 $ip 默认 null，
 *   本服务内部回退到构造注入的 $requestIp。
 * - 显式传入非 null $ip 优先；用于需要覆写的特殊场景。
 * - 审计 module（仅 admin_log）由本类持有 Request 引用，在 writeAdminLog 调用期取
 *   {@see Request::routeModule()}；该值必须在 Router::dispatch 写入 routeModule 之后才有效，
 *   构造期取值必为空串。
 */
class AuditService extends BaseService
{
    /** @var string 由 InitTrait 在容器绑定 Request 之后构造注入的来源 IP */
    private $requestIp;

    /** @var Request|null 由 InitTrait 构造期注入的 Request 引用，仅供 writeAdminLog 取 routeModule */
    private $request;

    /**
     * @param string $requestIp shell 边界一次取定的来源 IP；CLI / 无 Request 场景传空串
     * @param Request|null $request 由 InitTrait 构造期注入，CLI / 无 Request 场景传 null
     */
    public function __construct($requestIp = '', $request = null)
    {
        $this->requestIp = (string) $requestIp;
        // 隐式 nullable 兼容（5.6 无 ?Type、8.4 弃用隐式 nullable）：去类型提示，体内守护 Request|null。
        $this->request = ($request instanceof Request) ? $request : null;
    }

    /**
     * 写入会员审计日志。
     *
     * $ip 默认 null，由 AuditService 回退到构造注入的 $requestIp；
     * 业务调用面正常情形下不传 $ip，仅在需要显式覆写时显式传值。
     *
     * @param mixed $userId
     * @param mixed $action
     * @param int $result
     * @param string $details
     * @param string|null $ip null 时回退到构造注入的 $requestIp
     * @return void
     */
    public function writeUserLog($userId, $action, $result = 0, $details = '', $ip = null)
    {
        $userId = intval($userId);
        $ip = $ip === null ? $this->requestIp : (string) $ip;
        DB::table('user_log')->data(array(
            'user_id' => $userId,
            'action' => $action,
            'ip' => $ip,
            'created_at' => date('Y-m-d H:i:s'),
            'result' => $result ? 1 : 0,
            'details' => $details,
        ))->insert();
    }

    /**
     * 写入预约审计日志。
     *
     * $ip 默认 null，由 AuditService 回退到构造注入的 $requestIp。
     *
     * @param mixed $bookId
     * @param mixed $action
     * @param mixed $beforeStatus
     * @param mixed $afterStatus
     * @param string $remark
     * @param string $operatorType 操作者类型 admin/user/work
     * @param int $operatorId 操作者 ID
     * @param string|null $ip null 时回退到构造注入的 $requestIp
     * @return bool
     */
    public function writeBookLog($bookId, $action, $beforeStatus, $afterStatus, $remark = '', $operatorType = 'admin', $operatorId = 0, $ip = null)
    {
        $bookId = intval($bookId);
        $beforeStatus = intval($beforeStatus);
        $afterStatus = intval($afterStatus);
        $operatorId = intval($operatorId);
        $ip = $ip === null ? $this->requestIp : (string) $ip;

        if (!$bookId) {
            return false;
        }

        return DB::table('book_log')->data(array(
            'book_id' => $bookId,
            'operator_type' => $operatorType,
            'operator_id' => $operatorId,
            'action' => $action,
            'before_status' => $beforeStatus,
            'after_status' => $afterStatus,
            'remark' => $remark,
            'ip' => $ip,
            'created_at' => date('Y-m-d H:i:s'),
        ))->insert() !== false;
    }

    /**
     * 写入后台操作审计日志。
     *
     * $module 默认空串，由本类调用期回退到 {@see Request::routeModule()}；
     * $ip 默认 null，由本类回退到构造注入的 $requestIp。
     *
     * **fail-fast**：$action 必须是 {@see \Dou\Core\Service\Admin\AdminLogAction} 字典常量
     * （非空字符串）；空串 / 非字符串立即抛 InvalidArgumentException。设计目的是让任何
     * 位置参数颠倒、漏改的调用形态在第一次回归即 fatal 暴露出来，
     * 而不是 intval('xxx中文')->0->Session 兜底后写脏数据。
     *
     * @param int|mixed $adminId 当前操作管理员 ID；登录失败等未登录场景显式传 0，由本类 Session 兜底
     * @param string $action AdminLogAction 字典常量（必填，非空字符串）
     * @param int $result 0 失败 / 1 成功
     * @param string $details 业务对象或 AdminLogDetail 字典枚举标签
     * @param string $module 路由模块短名；空串时调用期取 Request::routeModule()
     * @param string|null $ip null 时回退到构造注入的 $requestIp
     * @return void
     * @throws InvalidArgumentException 当 $action 不是非空字符串
     */
    public function writeAdminLog($adminId, $action, $result = 0, $details = '', $module = '', $ip = null)
    {
        if (!is_string($action) || $action === '') {
            throw new InvalidArgumentException(
                'writeAdminLog $action must be a non-empty string constant from AdminLogAction'
            );
        }

        $adminId = intval($adminId);
        if (!$adminId) {
            $adminId = intval(Session::get('admin_id', 0));
        }

        $action = xss() ? xss()->text($action) : $action;
        $details = xss() ? xss()->text((string) $details) : (string) $details;

        $module = (string) $module;
        if ($module === '' && $this->request !== null) {
            $module = (string) $this->request->routeModule();
        }

        $ip = $ip === null ? $this->requestIp : (string) $ip;

        DB::table('admin_log')->insert(array(
            'admin_id' => $adminId,
            'action' => $action,
            'module' => $module,
            'ip' => $ip,
            'created_at' => date('Y-m-d H:i:s'),
            'result' => $result ? 1 : 0,
            'details' => $details,
        ));
    }
}
