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

namespace Dou\Admin\Service\Auth;

use Dou\Admin\Model\Manager\Manager;
use Dou\Core\Facade\DB;
use Dou\Core\Facade\Session;
use Dou\Core\Foundation\Auth\StatefulGuardContract;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台管理员认证 Guard。
 *
 * 实现 {@see StatefulGuardContract}：作为后台管理员身份解析与登录状态写入的
 * 唯一入口；业务调用面统一使用 `auth('admin')->method()` 完整写法。
 *
 * 职责边界：
 * - 身份解析：{@see id()} / {@see user()} / {@see check()} / {@see guest()}
 * - 登录写入：{@see attempt()} / {@see login()} / {@see logout()}
 * - Init 期会话恢复：{@see restoreFromSession()}
 * - 登录前置检测（编排层使用）：{@see ipRateLimited()} / {@see isLocked()} / {@see lockSecondsRemaining()}
 *
 * 编排（验证码 / 提示文案 / event 触发 / cache 清理 / redirect）由
 * {@see \Dou\Admin\Service\Login\AdminLoginFlow} 串接，本类**不**承担。
 * 密码重置流程由 {@see \Dou\Admin\Service\Login\PasswordResetService} 承担。
 *
 * 后台菜单 / 权限判定不在本类内：
 * - 菜单元数据 → {@see \Dou\Admin\Service\Menu\AdminMenuService::basicMenu()}
 * - 权限判定 → {@see \Dou\Admin\Service\Authorization\AdminGate}
 */
class AuthService extends BaseService implements StatefulGuardContract
{
    /** @var array 当前管理员信息（未登录为空数组） */
    private $adminProfile = array();

    /** @var int 当前管理员 ID（未登录为 0） */
    private $adminProfileId = 0;

    public function __construct()
    {
    }

    // ---------------------------------------------------------------------
    // GuardContract / StatefulGuardContract 实现
    // ---------------------------------------------------------------------

    /**
     * 当前管理员 ID；未登录返回 0。
     *
     * @return int
     */
    public function id()
    {
        return $this->adminProfileId;
    }

    /**
     * 当前管理员资料行；未登录返回空数组。
     *
     * @return array
     */
    public function user()
    {
        return $this->adminProfile;
    }

    /**
     * 当前请求是否已认证为后台管理员。
     *
     * @return bool
     */
    public function check()
    {
        return $this->adminProfileId > 0;
    }

    /**
     * 当前请求是否未认证。
     *
     * @return bool
     */
    public function guest()
    {
        return $this->adminProfileId <= 0;
    }

    /**
     * 凭据登录尝试。
     *
     * 仅承担「校验凭据 → 写入主体登录态」核心动作；不做验证码、IP 限流以外的
     * 编排逻辑（这些由 {@see \Dou\Admin\Service\Login\AdminLoginFlow} 处理）。
     *
     * IP 限流命中、账号锁定、用户不存在、密码错误均返回 false；
     * 编排层应在调用前用 {@see ipRateLimited()} / {@see isLocked()} 等方法
     * 做预检以输出具体提示。
     *
     * @param array $credentials username / password
     * @param bool $remember 是否同时发放 remember-me 续登凭证
     * @param string $ip 客户端 IP，业务用于 ip 限流 + 写入 admin.last_ip
     * @return bool 是否登录成功
     */
    public function attempt(array $credentials, $remember = false, $ip = '')
    {
        $username = isset($credentials['username']) ? trim((string) $credentials['username']) : '';
        $password = isset($credentials['password']) ? (string) $credentials['password'] : '';

        if ($username === '' || $password === '') {
            return false;
        }

        if ($this->ipRateLimited((string) $ip)) {
            return false;
        }

        $userModel = Manager::findByUsername($username);
        if (!$userModel) {
            return false;
        }
        $user = $userModel->getAttributes();

        if ($this->isLocked($user['id'])) {
            return false;
        }

        if (!$this->verifyPassword($password, $user)) {
            $this->recordLoginFail($user['id']);
            return false;
        }

        $userModel = Manager::find($user['id']);
        if (!$userModel) {
            // 竞态：密码校验后该行被删除 / 不可读。login(array $user) 收 null 会 TypeError，直接判失败。
            return false;
        }
        $this->login($userModel->getAttributes(), $remember, (string) $ip);

        return true;
    }

    /**
     * 已知主体行直接登录（注册后立刻登录 / 代登场景）。
     *
     * @param array $user 至少含 admin_id / username / password 的管理员行
     * @param bool $remember 是否发放 remember-me 续登凭证
     * @param string $ip 客户端 IP，用于写入 admin.last_ip
     * @return void
     */
    public function login(array $user, $remember = false, $ip = '')
    {
        session_regenerate_id(true);

        Session::set('admin_id', $user['id']);
        Session::set('shell', md5($user['username'] . $user['password'] . DOU_SHELL));
        Session::set('ontime', time());

        Manager::resetLoginFailState($user['id']);
        Manager::updateLastLogin($user['id'], (string) $ip, time());

        if ($remember) {
            $this->issueRememberToken($user['id']);
        }

        $this->hydrate($this->buildAdminPayload($user));
    }

    /**
     * 登出当前管理员：清 session、清 remember 凭证、重置实例状态。
     *
     * @return void
     */
    public function logout()
    {
        Session::clear();
        setcookie(DOU_TOKEN, '', time() - 3600, '/', '', IS_HTTPS, true);
        $this->reset();
    }

    // ---------------------------------------------------------------------
    // Init 期会话恢复（仅供 admin Init 调用）
    // ---------------------------------------------------------------------

    /**
     * 从 session / remember cookie 恢复管理员上下文。
     *
     * Init 期专用入口；恢复成功后内部 hydrate 完成、返回 payload；
     * 恢复失败时实例为 guest 状态、返回 null。
     *
     * @param string $ip 客户端 IP，由 middleware 边界显式传入（仅用于 remember-me 自动续登写 admin.last_ip）
     * @return array|null
     */
    public function restoreFromSession($ip = '')
    {
        $this->tryRememberLogin((string) $ip);

        $adminId = intval(Session::get('admin_id', 0));
        $shell = (string) Session::get('shell', '');

        $admin = $this->findBySession($adminId, $shell);
        if (!$admin) {
            $this->reset();
            return null;
        }

        $this->touchSession();

        $payload = $this->buildAdminPayload($admin);
        $this->hydrate($payload);

        return $payload;
    }

    // ---------------------------------------------------------------------
    // 状态注入与重置
    // ---------------------------------------------------------------------

    /**
     * 注入后台管理员上下文（由 restoreFromSession / login 内部调用，
     * 也可被测试代码直接调用以模拟已登录状态）。
     *
     * @param array|null $admin
     * @return void
     */
    public function hydrate($admin = null)
    {
        // 隐式 nullable 兼容（5.6 无 ?Type、8.4 弃用隐式 nullable）：去 array 提示，下方 is_array 守护已覆盖。
        if (!is_array($admin) || empty($admin['admin_id'])) {
            $this->reset();
            return;
        }

        $this->adminProfile = $admin;
        $this->adminProfileId = (int) $admin['admin_id'];
    }

    /**
     * 清空后台管理员上下文。
     *
     * @return void
     */
    public function reset()
    {
        $this->adminProfile = array();
        $this->adminProfileId = 0;
    }

    // ---------------------------------------------------------------------
    // 登录前置检测（供编排层 AdminLoginFlow 调用）
    // ---------------------------------------------------------------------

    /**
     * 当前请求 IP 是否处于登录失败限流期。
     *
     * @param string $ip 客户端 IP，由编排层显式传入
     * @param int $limit 失败阈值
     * @param int $window 时间窗（秒）
     * @return bool
     */
    public function ipRateLimited($ip, $limit = 10, $window = 600)
    {
        $since = time() - $window;
        return Manager::countLoginFailuresByIp((string) $ip, $since) >= $limit;
    }

    /**
     * 指定管理员是否处于账号锁定期。
     *
     * @param int $adminId
     * @return bool
     */
    public function isLocked($adminId)
    {
        return Manager::getLoginLockTime((int) $adminId) > time();
    }

    /**
     * 指定管理员当前锁定剩余秒数（未锁定返回 0）。
     *
     * @param int $adminId
     * @return int
     */
    public function lockSecondsRemaining($adminId)
    {
        $lockTime = (int) Manager::getLoginLockTime((int) $adminId);
        $remain = $lockTime - time();
        return $remain > 0 ? $remain : 0;
    }

    // ---------------------------------------------------------------------
    // 内部辅助
    // ---------------------------------------------------------------------

    /**
     * 构建写入登录态（Session / 上下文）的管理员摘要信息。
     *
     * @param array $admin
     * @return array
     */
    private function buildAdminPayload(array $admin)
    {
        $type = ($admin['action_list'] === 'ALL' || $admin['action_list'] === 'ADMIN')
            ? strtolower($admin['action_list'])
            : 'defined';

        return array(
            'admin_id' => $admin['id'],
            'username' => $admin['username'],
            'email' => $admin['email'],
            'type' => $type,
            'action_list' => $admin['action_list'],
            'user_id' => $admin['id'],
        );
    }

    /**
     * 通过会话 admin_id + shell 校验管理员身份。
     *
     * @param mixed $adminId
     * @param mixed $shell
     * @return array|null
     */
    private function findBySession($adminId, $shell)
    {
        $user = DB::table('admin')->where('id', $adminId)->find();
        if (!is_array($user)) {
            return null;
        }

        $checkShell = md5($user['username'] . $user['password'] . DOU_SHELL);
        return hash_equals($checkShell, (string) $shell) ? $user : null;
    }

    /**
     * 刷新后台会话心跳，超时时清空会话。
     *
     * @param int $timeout
     * @return void
     */
    private function touchSession($timeout = 604800)
    {
        $ontime = (int) Session::get('ontime', 0);
        if (time() - $ontime > $timeout) {
            Session::clear();
        } else {
            Session::set('ontime', time());
        }
    }

    /**
     * 尝试通过 remember_token Cookie 自动登录。
     *
     * 续登成功后会一并补发 CSRF 静态令牌（static_admin），保证 PHP session 文件
     * 被 GC 后通过 remember-me 重建的会话也持有可用的表单 token，避免后续任意
     * POST 操作走 {@see \Dou\Admin\Middleware\CsrfMiddleware} 的校验失败分支触发"非法操作"+
     * Session::clear() 假性登出。
     *
     * @param string $ip 客户端 IP，用于 remember-me 续登成功时写 admin.last_ip
     * @return void
     */
    private function tryRememberLogin($ip)
    {
        if (Session::has('admin_id')) {
            return;
        }
        if (!isset($_COOKIE[DOU_TOKEN])) {
            return;
        }

        $tokenHash = hash('sha256', $_COOKIE[DOU_TOKEN]);
        $user = DB::table('admin')->where('token', $tokenHash)->find();
        $tokenExpiresAt = ($user && $user['token_expires_at']) ? strtotime((string) $user['token_expires_at']) : false;

        if ($user && $tokenExpiresAt !== false && $tokenExpiresAt > time()) {
            Session::set('admin_id', $user['id']);
            Session::set('shell', md5($user['username'] . $user['password'] . DOU_SHELL));
            Session::set('ontime', time());

            // 补发 CSRF 静态令牌，避免续登会话因缺 token 触发"非法操作"
            csrf()->generate('static_admin');

            DB::table('admin')
                ->where('id', $user['id'])
                ->update(array('last_login' => time(), 'last_ip' => (string) $ip));
        } else {
            setcookie(DOU_TOKEN, '', time() - 3600, '/', '', IS_HTTPS, true);
        }
    }

    /**
     * 校验输入密码；若库内为历史 md5 散列则即时升级为 bcrypt。
     *
     * @param string $passwordInput
     * @param array $user
     * @return bool
     */
    private function verifyPassword($passwordInput, array $user)
    {
        $stored = isset($user['password']) ? (string) $user['password'] : '';

        if (strlen($stored) == 32 && ctype_xdigit($stored)) {
            if (md5($passwordInput) === $stored) {
                Manager::updatePasswordHash($user['id'], password_hash($passwordInput, PASSWORD_BCRYPT));
                return true;
            }
            return false;
        }

        return password_verify($passwordInput, $stored);
    }

    /**
     * 记录登录失败次数；达阈值时锁定 15 分钟。
     *
     * @param int $adminId
     * @return void
     */
    private function recordLoginFail($adminId)
    {
        $userModel = Manager::find((int) $adminId);
        $user = $userModel ? $userModel->getAttributes() : null;
        if (!$user) {
            return;
        }

        $failCount = intval($user['login_fail_count']) + 1;
        $lockTime = $failCount >= 5 ? time() + 900 : 0;
        Manager::updateLoginFailState((int) $adminId, $failCount, $lockTime);
    }

    /**
     * 发放 remember-me 续登凭证（30 天）。
     *
     * @param int $adminId
     * @return void
     */
    private function issueRememberToken($adminId)
    {
        $token = Str::randomHex();
        $tokenHash = hash('sha256', $token);
        $tokenExpire = time() + 30 * 24 * 3600;
        Manager::updateRememberToken((int) $adminId, $tokenHash, $tokenExpire);
        setcookie(DOU_TOKEN, $token, $tokenExpire, '/', '', IS_HTTPS, true);
    }
}
