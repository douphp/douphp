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

namespace Dou\Admin\Service\Login;

use Dou\Admin\Service\Auth\AuthService;
use Dou\Admin\Service\Cache\CacheClearService;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Event\Event;
use Dou\Core\Foundation\Event\SystemEvents;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Exception\RedirectException;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Infra\Security\Captcha;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Admin\AdminLogDetail;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Check;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台登录编排服务。
 *
 * 串接「验证码 → 输入格式校验 → IP 限流 → 账号锁定检测 → 调用 guard.attempt
 * → 登录后副作用（csrf 令牌 / cache 清理 / event / redirect）」全流程；
 * 凭据校验与登录状态写入由 {@see AuthService::attempt()} 承担，本类**不**触碰
 * session / token 等底层登录态。
 */
class AdminLoginFlow extends BaseService
{
    /** @var AuthService */
    private $auth;

    /** @var CacheClearService */
    private $cacheClear;

    /** @var Captcha */
    private $captcha;

    /**
     * @param AuthService $auth
     * @param CacheClearService $cacheClear
     * @param Captcha $captcha
     */
    public function __construct(AuthService $auth, CacheClearService $cacheClear, Captcha $captcha)
    {
        $this->auth = $auth;
        $this->cacheClear = $cacheClear;
        $this->captcha = $captcha;
    }

    /**
     * 处理后台登录提交。
     *
     * 流程内所有失败分支统一抛 {@see DomainException}（'out' 模式），
     * 成功后抛 {@see RedirectException} 跳转后台首页。
     *
     * @param array $data validated 后的登录表单数据
     * @param string $ip 客户端 IP，由 controller 从 Request::ip() 取好显式传入
     * @return void
     */
    public function handle(array $data, $ip = '')
    {
        $loginUrl = route('admin.login');
        $clientIp = (string) $ip;

        $this->checkCaptcha($data, $loginUrl);

        $username = isset($data['username']) ? trim((string) $data['username']) : '';
        if (!Check::adminAccount($username)) {
            Log::warning('Admin login invalid username format', array(
                'channel' => 'auth',
                'username' => $username,
            ));
            $this->writeLoginFailAudit(0, AdminLogDetail::USERNAME_INVALID);
            throw new DomainException(lang('login_input_wrong'), $loginUrl, '', '', array(), 'out');
        }

        if ($this->auth->ipRateLimited($clientIp)) {
            Log::warning('Admin login ip rate limited', array(
                'channel' => 'auth',
                'ip' => $clientIp,
                'username' => $username,
            ));
            $this->writeLoginFailAudit(0, AdminLogDetail::IP_RATE_LIMITED);
            throw new DomainException(lang('login_input_wrong'), $loginUrl, '', '', array(), 'out');
        }

        $credentials = array(
            'username' => $username,
            'password' => isset($data['password']) ? (string) $data['password'] : '',
        );
        $remember = isset($data['remember']);

        if (!$this->auth->attempt($credentials, $remember, $clientIp)) {
            $this->handleAttemptFailure($username, $clientIp, $loginUrl);
        }

        $this->postLoginActions($clientIp);

        throw new RedirectException(route('admin.index'));
    }

    /**
     * 处理登出：清 session / 清 remember cookie，由调用方负责跳转。
     *
     * @return void
     */
    public function logout()
    {
        $this->auth->logout();
        throw new RedirectException(route('admin.login'));
    }

    // ---------------------------------------------------------------------
    // 内部步骤
    // ---------------------------------------------------------------------

    /**
     * 验证码校验（site.captcha 开启时执行）。
     *
     * 校验、TTL、最小年龄、一次性消费均收口到 {@see Captcha::verify()}：
     * 失败时由 verify 自动清掉 captcha session，限频由 admin 端 IP 限流兜底。
     *
     * @param array $data
     * @param string $loginUrl
     * @return void
     */
    private function checkCaptcha(array $data, $loginUrl)
    {
        if (!Config::get('site.captcha', false)) {
            return;
        }

        $captchaRaw = isset($data['captcha']) ? trim((string) $data['captcha']) : '';
        if (!$this->captcha->verify($captchaRaw)) {
            Log::warning('Admin login captcha mismatch', array('channel' => 'auth'));
            $this->writeLoginFailAudit(0, AdminLogDetail::CAPTCHA_WRONG);
            throw new DomainException(lang('login_captcha_wrong'), $loginUrl, '', '', array(), 'out');
        }
    }

    /**
     * 登录尝试失败：诊断具体原因输出对应文案 / 审计日志。
     *
     * @param string $username
     * @param string $clientIp
     * @param string $loginUrl
     * @return void
     */
    private function handleAttemptFailure($username, $clientIp, $loginUrl)
    {
        $user = DB::table('admin')->where('username', $username)->find();

        if (is_array($user) && $this->auth->isLocked($user['id'])) {
            $minutes = (int) ceil($this->auth->lockSecondsRemaining($user['id']) / 60);
            Log::warning('Admin login account locked', array(
                'channel' => 'auth',
                'admin_id' => (int) $user['id'],
                'ip' => $clientIp,
                'locked_minutes' => $minutes,
            ));
            $this->writeLoginFailAudit((int) $user['id'], AdminLogDetail::ACCOUNT_LOCKED);
            $msg = (lang('login_account_locked') ?: '账户已被锁定') . "，请 {$minutes} 分钟后重试";
            throw new DomainException($msg, $loginUrl, '', '', array(), 'out');
        }

        $failAdminId = 0;
        if (!is_array($user)) {
            Log::warning('Admin login user not found', array(
                'channel' => 'auth',
                'ip' => $clientIp,
                'username' => $username,
            ));
        } else {
            $failAdminId = (int) $user['id'];
            Log::warning('Admin login password invalid', array(
                'channel' => 'auth',
                'admin_id' => $failAdminId,
                'ip' => $clientIp,
            ));
        }

        $this->writeLoginFailAudit($failAdminId, AdminLogDetail::INPUT_WRONG);
        throw new DomainException(lang('login_input_wrong'), $loginUrl, '', '', array(), 'out');
    }

    /**
     * 登录失败审计写入（6 类失败分支共用）。
     *
     * @param int $adminId 试图登录的账号 ID；用户名不存在 / 用户名格式非法 / IP 限流 /
     *                     captcha 等无法定位账号时传 0
     * @param string $detailTag AdminLogDetail 字典枚举标签
     * @return void
     */
    private function writeLoginFailAudit($adminId, $detailTag)
    {
        audit()->writeAdminLog((int) $adminId, AdminLogAction::LOGIN_FAIL, 0, $detailTag);
    }

    /**
     * 登录成功后副作用：csrf 令牌（static_admin）/ cache 清理 / 验证码清理 / 审计日志 / event。
     *
     * @param string $ip 客户端 IP，用于派发登录成功事件
     * @return void
     */
    private function postLoginActions($ip)
    {
        csrf()->generate('static_admin');

        if (Config::get('site.root_url_cache', '') != ROOT_URL) {
            DB::table('config')->where('name', 'root_url_cache')->update(array('value' => ROOT_URL));
            $this->cacheClear->clearCache(STORAGE_PATH . 'cache/template');
        }

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::LOGIN_SUCCESS, 1);

        Event::fire(SystemEvents::ADMIN_LOGIN_SUCCESS, array(
            'admin' => $this->auth->user(),
            'ip' => (string) $ip,
            'lang' => lang_all(),
        ));
    }
}
