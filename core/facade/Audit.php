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

namespace Dou\Core\Facade;

use Dou\Core\Foundation\Facade\StaticFacade;
use Dou\Core\Service\Audit\AuditService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * Audit 静态门面：底层为 {@see AuditService} 容器单例。
 *
 * 与 helper `audit()` 等价。
 *
 * @method static void writeUserLog($userId, $action, $result = 0, $details = '', $ip = null)
 * @method static bool writeBookLog($bookId, $action, $beforeStatus, $afterStatus, $remark = '', $operatorType = 'admin', $operatorId = 0, $ip = null)
 * @method static void writeAdminLog($adminId, $action, $result = 0, $details = '', $module = '', $ip = null)
 */
class Audit extends StaticFacade
{
    /**
     * 容器中以 AuditService FQCN 为 key 注册。
     *
     * @return string
     */
    protected static function getAccessor()
    {
        return AuditService::class;
    }
}
