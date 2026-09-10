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

namespace Dou\Core\Service\System;

use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 功能开关闸（Domain 读模型）。
 *
 * 由 `Config('features')` 数组承担「某模块/功能是否打开」的判定，
 * 同时为后台暴露 `sort` 开关。
 *
 * 与 Session 解耦：是否含 `sort` 由调用方通过 `$includeAdminSort` + `$adminSort` 参数显式传入。
 */
class ModuleFeatureGate extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 计算 features 数组。
     *
     * @param array $modules 已合并的模块 id 列表（all_module）
     * @param bool $includeAdminSort 是否纳入后台 sort 开关（仅 admin 端为 true）
     * @param bool $adminSort 后台 sort 当前值（来源由调用方决定，如 Session）
     * @return array
     */
    public function resolve(array $modules, $includeAdminSort, $adminSort)
    {
        $features = array();
        foreach ($modules as $coreSettingId) {
            $features[$coreSettingId] = true;
        }

        if ($includeAdminSort) {
            $features['sort'] = (bool) $adminSort;
        }

        return $features;
    }
}
