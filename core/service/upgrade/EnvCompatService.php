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
 * Release Date: 2026-10-16
 */

namespace Dou\Core\Service\Upgrade;

use Dou\Core\Facade\DB;
use Dou\Core\Support\DbVersion;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 运行环境兼容判定（双端统一；判定口径与 devtools/mysql-compat-check 一致）。
 *
 * 用途：
 *   - PreflightService 基础检查：evaluate() 给出站点当前 PHP × MySQL 组合判定
 *     （blocked 拒绝 / limited 警告 / ok）；
 *   - 升级包环境要求复核：checkPackageRequirements() 按包内 version.php 的
 *     php_min / mysql_min 做 fail-loud 校验。
 */
class EnvCompatService
{
    /**
     * 当前 PHP × MySQL 组合判定。
     *
     * 规则（与 devtools/mysql-compat-check 同口径）：
     *   - PHP < 5.6.0 → blocked；
     *   - MySQL < 5.7（或版本无法识别）→ blocked；
     *   - PHP < 7.4 + MySQL 9.x → blocked（mysql_native_password 已移除、无法配置开启）；
     *   - PHP < 7.4 + MySQL 8.4 / 8.0 → limited（需 native 认证账号或升级 PHP）；
     *   - MySQL 9.x + 当前账号仍为 mysql_native_password → blocked（连接将报 1524）。
     *
     * @return array array('status' => 'ok'|'limited'|'blocked', 'reasons' => string[])
     */
    public function evaluate()
    {
        $verdict = array('status' => 'ok', 'reasons' => array());

        if (version_compare(PHP_VERSION, '5.6.0', '<')) {
            $verdict['status'] = 'blocked';
            $verdict['reasons'][] = 'PHP ' . PHP_VERSION . ' 低于最低要求 5.6.0';
        }

        $serverRaw = $this->serverVersionRaw();
        $normalized = $serverRaw !== '' ? DbVersion::normalize($serverRaw) : '';
        $family = $serverRaw !== '' ? DbVersion::serverFamily($serverRaw) : '';

        if ($serverRaw === '') {
            // 版本读取失败不阻断（保守），仅提示
            $verdict['reasons'][] = '无法读取数据库版本（SELECT VERSION() 失败），未做 MySQL 兼容判定';
        } else {
            if (!DbVersion::isAtLeast($serverRaw, '5.7')) {
                $verdict['status'] = 'blocked';
                $verdict['reasons'][] = $normalized === ''
                    ? 'MySQL 版本无法识别：' . $serverRaw
                    : 'MySQL ' . $normalized . ' 低于最低要求 5.7';
            }
            if ($family === 'mysql' && $normalized !== '' && version_compare(PHP_VERSION, '7.4.0', '<')) {
                if (version_compare($normalized, '9.0.0', '>=')) {
                    $verdict['status'] = 'blocked';
                    $verdict['reasons'][] = 'PHP < 7.4 无法连接 MySQL 9.x（mysql_native_password 已移除、无法配置开启），请升级 PHP ≥ 7.4';
                } elseif (version_compare($normalized, '8.4.0', '>=')) {
                    $verdict['status'] = 'limited';
                    $verdict['reasons'][] = 'PHP < 7.4 + MySQL 8.4：需在 my.cnf 开启 mysql_native_password=ON 并使用 native 认证账号，或升级 PHP ≥ 7.4';
                } elseif (version_compare($normalized, '8.0.0', '>=')) {
                    $verdict['status'] = 'limited';
                    $verdict['reasons'][] = 'PHP < 7.4 + MySQL 8.0：数据库账号须使用 mysql_native_password 认证，或升级 PHP ≥ 7.4';
                }
            }
            // 反向场景：账号仍是 native 插件但服务器已移除（MySQL 9.x），连接会报 1524
            if ($family === 'mysql' && $normalized !== ''
                && version_compare($normalized, '9.0.0', '>=')
                && $this->authPlugin() === 'mysql_native_password') {
                $verdict['status'] = 'blocked';
                $verdict['reasons'][] = '当前账号使用 mysql_native_password，但 MySQL 9.x 已移除该插件（连接将报错 1524），请将账号改为 caching_sha2_password（需 PHP ≥ 7.4）';
            }
        }

        return $verdict;
    }

    /**
     * 按升级包自述要求复核环境（version.php 的 php_min / mysql_min；缺失项跳过）。
     *
     * @param array $meta 物料根 version.php 返回的元信息数组
     * @return array array('ok' => bool, 'errors' => string[])
     */
    public function checkPackageRequirements(array $meta)
    {
        $errors = array();

        $phpMin = isset($meta['php_min']) ? trim((string) $meta['php_min']) : '';
        if ($phpMin !== '' && version_compare(PHP_VERSION, $phpMin, '<')) {
            $errors[] = '当前 PHP ' . PHP_VERSION . ' 低于升级包要求的最低版本 ' . $phpMin;
        }

        $mysqlMin = isset($meta['mysql_min']) ? trim((string) $meta['mysql_min']) : '';
        if ($mysqlMin !== '') {
            $serverRaw = $this->serverVersionRaw();
            if ($serverRaw !== '' && !DbVersion::isAtLeast($serverRaw, $mysqlMin)) {
                $errors[] = '当前 MySQL 低于升级包要求的最低版本 ' . $mysqlMin . '（当前 ' . $serverRaw . '）';
            }
        }

        return array('ok' => empty($errors), 'errors' => $errors);
    }

    /**
     * 数据库服务器版本原始串（读不到返回空串）。
     *
     * @return string
     */
    private function serverVersionRaw()
    {
        $version = DB::getOne('SELECT VERSION()');

        return is_string($version) ? trim($version) : '';
    }

    /**
     * 当前账号认证插件（无 mysql.user 查询权限时静默返回空串）。
     *
     * @return string
     */
    private function authPlugin()
    {
        try {
            $row = DB::fetchArray(DB::query(
                "SELECT plugin FROM mysql.user"
                . " WHERE user = SUBSTRING_INDEX(CURRENT_USER(), '@', 1)"
                . " AND host = SUBSTRING_INDEX(CURRENT_USER(), '@', -1) LIMIT 1"
            ));

            return (is_array($row) && isset($row['plugin'])) ? (string) $row['plugin'] : '';
        } catch (\Exception $e) {
            return '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
