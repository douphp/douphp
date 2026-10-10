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
 * Release Date: 2026-10-10
 */

namespace Dou\Core\Service\System;

use Dou\Core\Facade\DB;
use Dou\Core\Support\DbVersion;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 通用模块环境要求校验器。
 *
 * 模块通过包内 module_require.php 声明运行环境要求，站点级可用
 * config/module_require_custom.php 覆盖同键声明：
 *
 *   return array(
 *       'php'   => '>=7.4',                 // PHP 版本门槛（可选）
 *       'mysql' => '>=8.0.13',              // MySQL 版本门槛（可选，MariaDB 按真实版本比较）
 *       'capabilities' => array('vector'),  // 能力探测（可选，本期 fail-closed：声明即不满足）
 *   );
 *
 * 覆盖与升级安全说明：站点级覆盖文件沿用 *_custom.php 永不覆盖约定，不进官方升级包、
 * 不注册 config_merge 基准；能力探测本期仅留扩展点（{@see probeCapability}），
 * 不实现任何具体能力，知识库等模块的向量能力探测待其底座选型确定后再接入。
 */
class ModuleRequirementChecker
{
    /**
     * 收集当前环境信息。
     *
     * MySQL 版本取自 DB 门面；门面不可用时 mysql 相关字段返回空串，不抛异常，
     * 保证预检（可能早于数据库可用）也能输出环境快照。
     *
     * @return array php / mysql_raw / mysql（归一后）/ family 四个键
     */
    public static function currentEnvironment()
    {
        $mysqlRaw = '';
        try {
            $mysqlRaw = (string) DB::version();
        } catch (\Exception $e) {
            $mysqlRaw = '';
        } catch (\Error $e) {
            // PHP 7+ 下门面底层未绑定时抛 Error；PHP 5.6 无 Throwable，此分支仅 7+ 生效
            $mysqlRaw = '';
        }

        return array(
            'php' => PHP_VERSION,
            'mysql_raw' => $mysqlRaw,
            'mysql' => DbVersion::normalize($mysqlRaw),
            'family' => DbVersion::serverFamily($mysqlRaw),
        );
    }

    /**
     * 加载模块环境要求声明（双通道：包内声明 + 站点级覆盖）。
     *
     * @param string $packageDir 已解压的模块包根目录
     * @return array 声明数组（无声明或文件无效时返回空数组）
     */
    public static function loadRequire($packageDir)
    {
        $require = array();

        // 通道一：模块包内声明（module_require.php，随包分发）
        $file = rtrim((string) $packageDir, '/\\') . '/module_require.php';
        if (is_file($file)) {
            $declared = include $file;
            if (is_array($declared)) {
                $require = $declared;
            }
        }

        // 通道二：站点级覆盖（*_custom.php 永不覆盖约定；按键覆盖同键声明，如强制提高 php 门槛）
        $custom = ROOT_PATH . 'config/module_require_custom.php';
        if (is_file($custom)) {
            $customRequire = include $custom;
            if (is_array($customRequire)) {
                $require = array_merge($require, $customRequire);
            }
        }

        return $require;
    }

    /**
     * 校验要求清单。
     *
     * @param array $require 环境要求声明（php / mysql / capabilities）
     * @return array array('ok' => bool, 'items' => array(...))；
     *               items 每项含 key / require / current / pass，供调用方组装提示
     */
    public static function check($require)
    {
        $env = self::currentEnvironment();
        $mysqlCurrent = $env['mysql'] !== '' ? $env['mysql'] : $env['mysql_raw'];

        $items = array(
            self::buildItem('php', isset($require['php']) ? $require['php'] : '', $env['php']),
            self::buildItem('mysql', isset($require['mysql']) ? $require['mysql'] : '', $mysqlCurrent),
        );

        // capabilities 本期不实现具体探测：fail-closed，凡声明即视为不满足
        if (isset($require['capabilities']) && is_array($require['capabilities']) && $require['capabilities'] !== array()) {
            $items[] = array(
                'key' => 'capabilities',
                'require' => implode(', ', $require['capabilities']),
                'current' => '',
                'pass' => false,
            );
        }

        $ok = true;
        foreach ($items as $item) {
            if (!$item['pass']) {
                $ok = false;
                break;
            }
        }

        return array('ok' => $ok, 'items' => $items);
    }

    /**
     * 构建单条校验结果（require 为空表示无此要求，恒通过）。
     *
     * @param string $key 要求键名
     * @param string $requireExpr 比较表达式（如 '>=7.4'，支持 >=/<=/>/</=，缺省为 >=）
     * @param string $current 当前归一版本
     * @return array
     */
    private static function buildItem($key, $requireExpr, $current)
    {
        $requireExpr = trim((string) $requireExpr);
        $current = (string) $current;
        if ($requireExpr === '') {
            return array('key' => $key, 'require' => '', 'current' => $current, 'pass' => true);
        }

        $pass = false;
        if (preg_match('/^(>=|<=|>|<|=)?\s*(.+)$/', $requireExpr, $match)) {
            $operator = $match[1] !== '' ? $match[1] : '>=';
            $version = trim($match[2]);
            // 两侧均为归一版本号，直接走 version_compare（CalVer 26.7.0 亦为合法三段数值）
            $pass = version_compare($current, $version, $operator === '=' ? '==' : $operator);
        }

        return array('key' => $key, 'require' => $requireExpr, 'current' => $current, 'pass' => (bool) $pass);
    }

    /**
     * 能力探测扩展点（占位，本期不实现任何具体能力）。
     *
     * 未来知识库等模块需要向量能力等非版本号可表达的特性时，在此按能力名探测并返回
     * bool；返回 null 表示当前版本不支持该能力探测（check() 按 fail-closed 处理）。
     *
     * @param string $name 能力名
     * @return bool|null
     */
    public static function probeCapability($name)
    {
        return null;
    }
}
