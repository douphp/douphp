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

use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台管理员操作日志（admin_log 表）AR 模型。
 *
 * 仅承载实体行的 casts；created_at 保留为原始时间戳，由
 * {@see \Dou\Admin\Service\Manager\ManagerService::renderAdminLogRow} 统一格式化。
 */
class ManagerAdminLog extends Model
{
    /** @var string */
    protected $table = 'admin_log';

    /** @var string */
    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'admin_id' => 'int',
        'result' => 'int',
    );

    /**
     * 强制空结果集（rejectFilter 兜底，配合权限校验未通过时使用）。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyForceEmpty(Builder $query)
    {
        return $query->where('id', -1);
    }

    /**
     * 按管理员 admin_id 精确筛选；null 透传，-1 视作强制空集。
     *
     * @param Builder $query
     * @param mixed $adminId
     * @return Builder
     */
    public function scopeFilterByAdminId(Builder $query, $adminId)
    {
        if ($adminId === null) {
            return $query;
        }

        return $query->where('admin_id', (int) $adminId);
    }

    /**
     * 按动作字典常量精确筛选；空字符串透传。
     *
     * @param Builder $query
     * @param mixed $action
     * @return Builder
     */
    public function scopeFilterByAction(Builder $query, $action)
    {
        $action = (string) $action;
        if ($action === '') {
            return $query;
        }

        return $query->where('action', $action);
    }

    /**
     * 按模块名精确筛选；空字符串透传。
     *
     * @param Builder $query
     * @param mixed $module
     * @return Builder
     */
    public function scopeFilterByModule(Builder $query, $module)
    {
        $module = (string) $module;
        if ($module === '') {
            return $query;
        }

        return $query->where('module', $module);
    }

    /**
     * 按 IP 精确筛选；空字符串透传。
     *
     * @param Builder $query
     * @param mixed $ip
     * @return Builder
     */
    public function scopeFilterByIp(Builder $query, $ip)
    {
        $ip = (string) $ip;
        if ($ip === '') {
            return $query;
        }

        return $query->where('ip', $ip);
    }

    /**
     * 按起始时间戳筛选（created_at >= $ts）；null 透传。
     *
     * @param Builder $query
     * @param mixed $ts
     * @return Builder
     */
    public function scopeFilterByDateStartTs(Builder $query, $ts)
    {
        if ($ts === null) {
            return $query;
        }

        return $query->where('created_at', '>=', date('Y-m-d H:i:s', (int) $ts));
    }

    /**
     * 按结束时间戳筛选（created_at <= $ts）；null 透传。
     *
     * @param Builder $query
     * @param mixed $ts
     * @return Builder
     */
    public function scopeFilterByDateEndTs(Builder $query, $ts)
    {
        if ($ts === null) {
            return $query;
        }

        return $query->where('created_at', '<=', date('Y-m-d H:i:s', (int) $ts));
    }

    /**
     * 默认列表排序：id DESC。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('id DESC');
    }
}
