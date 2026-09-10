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

use Dou\Admin\Model\Manager\Manager;
use Dou\Admin\Service\Mail\SiteMail;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Check;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台密码重置流程。
 *
 * 与 {@see \Dou\Admin\Service\Auth\AuthService} 解耦：本类只处理「忘记密码 → 发邮件
 * → 提交新密码」的独立业务，不写登录态，不依赖当前 guard。
 */
class PasswordResetService extends BaseService
{
    public function __construct()
    {
    }

    /**
     * 密码重置 POST。
     *
     * @param array $data validated 数据
     * @return array message、back_url、out、timeout
     * @throws DomainException 失败路径抛出（含输入校验、token 校验、邮件发送等）
     */
    public function passwordResetPost(array $data)
    {
        $action = isset($data['action']) && $data['action'] === 'reset' ? 'reset' : 'default';
        if ($action === 'reset') {
            $this->passwordResetComplete($data);

            return array(
                'message' => lang('login_password_reset_success'),
                'back_url' => route('admin.login'),
                'out' => 'out',
                'timeout' => '15',
            );
        }

        $email = $this->passwordResetRequest($data);

        return array(
            'message' => lang('login_password_mail_success') . $email,
            'back_url' => route('admin.login'),
            'out' => 'out',
            'timeout' => '30',
        );
    }

    /**
     * 密码重置页面数据：未带 token 时为发起表单，带 token 时为重置表单。
     *
     * @param mixed $adminId
     * @param mixed $code
     * @return array|null
     */
    public function buildPasswordResetData($adminId, $code)
    {
        if ($adminId && $code) {
            if (!$this->validatePasswordResetToken($adminId, $code)) {
                return null;
            }

            return array(
                'action' => 'reset',
                'admin_id' => $adminId,
                'code' => $code,
            );
        }

        return array(
            'action' => 'default',
        );
    }

    /**
     * 重置 token 校验（供 Controller 与 Service 复用）。
     *
     * @param mixed $admin_id
     * @param mixed $code
     * @param int $timeout
     * @return bool
     */
    public function validatePasswordResetToken($admin_id, $code, $timeout = 86400)
    {
        $userModel = Manager::find($admin_id);
        $user = $userModel ? $userModel->getAttributes() : null;
        if (!$user) {
            return false;
        }

        $resetExpiresAt = $user['reset_token_expires_at'] ? strtotime((string) $user['reset_token_expires_at']) : false;
        if ($user['reset_token'] && $resetExpiresAt !== false && $resetExpiresAt > time() && hash_equals($user['reset_token'], hash('sha256', $code))) {
            return true;
        }

        return false;
    }

    // ---------------------------------------------------------------------
    // 内部步骤
    // ---------------------------------------------------------------------

    /**
     * 发起重置：校验账号 + 发邮件。
     *
     * @param array $data
     * @return string 已发出邮件的邮箱地址
     * @throws DomainException 校验 / 邮件发送失败时抛出
     */
    private function passwordResetRequest(array $data)
    {
        $username = '';
        $email = '';
        $resetUrl = route('admin.login.password_reset');
        $resetUrlFull = ROOT_URL . ADMIN_DIR . '/' . $resetUrl;

        if (!isset($data['username']) || !Check::adminAccount(trim((string) $data['username']))) {
            throw new DomainException(lang('login_password_reset_fail'), $resetUrlFull, '', '', array(), 'out');
        }
        $username = trim((string) $data['username']);

        if (!isset($data['email']) || !Check::email(trim((string) $data['email']))) {
            throw new DomainException(lang('login_password_reset_fail'), $resetUrlFull, '', '', array(), 'out');
        }
        $email = trim((string) $data['email']);

        $user = Manager::findByUsernameAndEmail($username, $email);
        if (!$user) {
            Log::warning('Admin password reset user mismatch', array(
                'channel' => 'auth',
                'username' => $username,
                'email' => $email,
            ));
            throw new DomainException(lang('login_password_reset_wrong'), $resetUrlFull, '', '', array(), 'out');
        }

        if (function_exists('random_bytes')) {
            $raw = random_bytes(32);
        } else {
            $raw = openssl_random_pseudo_bytes(32);
        }
        $resetToken = bin2hex($raw);

        Manager::updatePasswordResetToken($user['id'], hash('sha256', $resetToken), time() + 86400);

        $resetUrlBodySegment = ROOT_URL . ADMIN_DIR . '/' . route('admin.login.password_reset', array(), array(
            'query' => array(
                'uid' => $user['id'],
                'code' => $resetToken,
            ),
        ));

        if (SiteMail::sendAdminPasswordResetMail($user['email'], lang_all(), $user->getAttributes(), $resetUrlBodySegment)) {
            return (string) $user['email'];
        }

        Log::error('Admin password reset mail send failed', array(
            'channel' => 'auth',
            'admin_id' => (int) $user['id'],
            'email' => $user['email'],
        ));
        throw new DomainException(lang('mail_send_fail'), $resetUrlFull, '30', '', array(), 'out');
    }

    /**
     * 完成重置：校验 token + 写入新密码。
     *
     * @param array $data
     * @return void
     */
    private function passwordResetComplete(array $data)
    {
        $admin_id = isset($data['admin_id']) && Check::number($data['admin_id']) ? $data['admin_id'] : '';
        $code = isset($data['code']) && preg_match('/^[a-zA-Z0-9]+$/', $data['code']) ? $data['code'] : '';
        $pwd = isset($data['password']) ? (string) $data['password'] : '';
        $pwdConfirm = isset($data['password_confirm']) ? (string) $data['password_confirm'] : '';

        if (!Check::password($pwd)) {
            throw new DomainException(lang('manager_password_cue'), '', '', '', array(), 'out');
        }
        if ($pwdConfirm !== $pwd) {
            throw new DomainException(lang('manager_password_confirm_cue'), '', '', '', array(), 'out');
        }

        if ($this->validatePasswordResetToken($admin_id, $code)) {
            Manager::completePasswordReset($admin_id, password_hash($pwd, PASSWORD_BCRYPT));

            return;
        }

        Log::warning('Admin password reset token invalid', array(
            'channel' => 'auth',
            'admin_id' => (int) $admin_id,
        ));
        throw new DomainException(lang('login_password_reset_fail'), route('admin.login'), '15', '', array(), 'out');
    }
}
