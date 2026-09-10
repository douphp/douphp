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

namespace Dou\Admin\Model\Manager;

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Model;
use Dou\Core\Service\Admin\AdminLogAction;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台管理员（admin 表）
 *
 * $fillable 声明允许通过 Model::fill 写入的字段；明文密码由 Service 哈希后再传入。
 * 表单校验与白名单见 ManagerFormRequest::rules()。
 */
class Manager extends Model
{
    protected $table = 'admin';
    protected $primary = 'id';

    /**
     * 允许批量写入的字段（持久化层）；与 ManagerFormRequest 分工同 ArticleModel。
     *
     * @var array
     */
    protected $fillable = array(
        'username',
        'email',
        'password',
        'action_list',
        'created_at',
    );

    /**
     * @return array
     */
    public static function getAllOrdered()
    {
        return static::order('id ASC')->get();
    }

    /**
     * @param string $username
     * @return \Dou\Core\Orm\Model|null
     */
    public static function findByUsername($username)
    {
        return static::where('username', $username)->first();
    }

    /**
     * @param string $email
     * @return \Dou\Core\Orm\Model|null
     */
    public static function findByEmail($email)
    {
        return static::where('email', $email)->first();
    }

    /**
     * @param int $id
     * @return string
     */
    public static function getUsernameById($id)
    {
        return DB::table(static::tableName())->where('id', intval($id))->value('username');
    }

    /**
     * @param string $username
     * @param string $email
     * @return array|null
     */
    public static function findByUsernameAndEmail($username, $email)
    {
        return static::where('username', $username)
            ->where('email', $email)
            ->first();
    }

    /**
     * @param int $adminId
     * @param string $passwordHash
     * @return bool
     */
    public static function updatePasswordHash($adminId, $passwordHash)
    {
        return static::where('id', intval($adminId))->update(array(
            'password' => $passwordHash,
        ));
    }

    /**
     * @param int $adminId
     * @param int $failCount
     * @param int $lockTime
     * @return bool
     */
    public static function updateLoginFailState($adminId, $failCount, $lockTime)
    {
        return static::where('id', intval($adminId))->update(array(
            'login_fail_count' => (int) $failCount,
            'login_locked_at' => (int) $lockTime,
        ));
    }

    /**
     * @param int $adminId
     * @return bool
     */
    public static function resetLoginFailState($adminId)
    {
        return static::updateLoginFailState($adminId, 0, 0);
    }

    /**
     * @param int $adminId
     * @return int
     */
    public static function getLoginLockTime($adminId)
    {
        $lockedAt = DB::table(static::tableName())->where('id', intval($adminId))->value('login_locked_at');
        $ts = $lockedAt ? strtotime((string) $lockedAt) : false;

        return $ts === false ? 0 : (int) $ts;
    }

    /**
     * @param int $adminId
     * @param string $ip
     * @param int $time
     * @return bool
     */
    public static function updateLastLogin($adminId, $ip, $time)
    {
        return static::where('id', intval($adminId))->update(array(
            'last_login' => (int) $time,
            'last_ip' => $ip,
        ));
    }

    /**
     * @param int $adminId
     * @param string $tokenHash
     * @param int $tokenExpire
     * @return bool
     */
    public static function updateRememberToken($adminId, $tokenHash, $tokenExpire)
    {
        return static::where('id', intval($adminId))->update(array(
            'token' => $tokenHash,
            'token_expires_at' => $tokenExpire > 0 ? date('Y-m-d H:i:s', (int) $tokenExpire) : null,
        ));
    }

    /**
     * @param int $adminId
     * @param string $resetTokenHash
     * @param int $resetTokenExpire
     * @return bool
     */
    public static function updatePasswordResetToken($adminId, $resetTokenHash, $resetTokenExpire)
    {
        return static::where('id', intval($adminId))->update(array(
            'reset_token' => $resetTokenHash,
            'reset_token_expires_at' => (int) $resetTokenExpire,
        ));
    }

    /**
     * @param int $adminId
     * @param string $passwordHash
     * @return bool
     */
    public static function completePasswordReset($adminId, $passwordHash)
    {
        return static::where('id', intval($adminId))->update(array(
            'password' => $passwordHash,
            'reset_token' => '',
            'reset_token_expires_at' => null,
        ));
    }

    /**
     * @param string $ip
     * @param int $since
     * @return int
     */
    public static function countLoginFailuresByIp($ip, $since)
    {
        return (int) DB::table('admin_log')
            ->where('ip', $ip)
            ->where('action', AdminLogAction::LOGIN_FAIL)
            ->where('created_at', '>=', date('Y-m-d H:i:s', (int) $since))
            ->count();
    }
}
