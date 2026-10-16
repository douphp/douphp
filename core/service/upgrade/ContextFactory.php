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
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 升级上下文装配工厂（双端统一）。
 *
 * - fromWizard：/upgrade/ 向导渠道。Init 已填好 siteConfig / prefix / 数据库参数与 UI 依赖
 *   （view / csrf / lang），此处补齐执行字段（materialsRoot / 目标版本 / 环境开关）。
 * - fromCloud：后台云更新渠道（_update/action.php 载荷）。在云更新窗口内装配：整包覆盖已落盘、
 *   核心库已是最新，DB 门面可用；数据库参数取自 config/config.php 全局变量（1.x 兼容层同源）。
 */
class ContextFactory
{
    /**
     * 向导渠道装配。
     *
     * @param UpgradeContext $ctx 由 upgrade/init/Init 装配的上下文（含 UI 依赖与站点配置快照）
     * @return UpgradeContext
     */
    public static function fromWizard(UpgradeContext $ctx)
    {
        $ctx->channel = 'wizard';
        $ctx->rootPath = defined('ROOT_PATH') ? ROOT_PATH : '';
        $ctx->materialsRoot = defined('UPGRADE_PATH') ? UPGRADE_PATH : '';
        $ctx->rootUrl = defined('ROOT_URL') ? (string) ROOT_URL : '';
        self::fillRuntime($ctx);

        return $ctx;
    }

    /**
     * 云渠道装配。
     *
     * @param UpgradeContext $ctx
     * @return UpgradeContext
     */
    public static function fromCloud(UpgradeContext $ctx)
    {
        $ctx->channel = 'cloud';
        $ctx->rootPath = defined('ROOT_PATH') ? ROOT_PATH : '';
        $ctx->materialsRoot = defined('UPGRADE_PATH') ? UPGRADE_PATH : (defined('U_PATH') ? U_PATH : '');
        $ctx->rootUrl = defined('ROOT_URL') ? (string) ROOT_URL : '';

        // 数据库连接参数（config/config.php 全局变量；1.x 兼容层同源）
        $ctx->dbhost = isset($GLOBALS['dbhost']) ? (string) $GLOBALS['dbhost'] : '';
        $ctx->dbname = isset($GLOBALS['dbname']) ? (string) $GLOBALS['dbname'] : '';
        $ctx->dbuser = isset($GLOBALS['dbuser']) ? (string) $GLOBALS['dbuser'] : '';
        $ctx->dbpass = isset($GLOBALS['dbpass']) ? (string) $GLOBALS['dbpass'] : '';
        $ctx->prefix = isset($GLOBALS['prefix']) ? (string) $GLOBALS['prefix'] : 'dou_';

        // dou_config 全量快照（升级启动真值，任何写入之前读取）
        $ctx->siteConfig = self::readSiteConfig();

        self::fillRuntime($ctx);

        return $ctx;
    }

    /**
     * 读取 dou_config 全量配置（key => value）。
     *
     * @return array
     */
    private static function readSiteConfig()
    {
        $config = array();
        $rows = DB::table('config')->field('name, value')->select();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (isset($row['name'])) {
                    $config[$row['name']] = isset($row['value']) ? $row['value'] : '';
                }
            }
        }

        return $config;
    }

    /**
     * 补齐双端共有的执行字段：目标版本（version.php 单一来源）、升级前版本与日期、环境开关。
     *
     * @param UpgradeContext $ctx
     * @return void
     */
    private static function fillRuntime(UpgradeContext $ctx)
    {
        $registry = new VersionRegistry($ctx->materialsRoot);
        $ctx->newVersion = $registry->targetVersion();
        if ($ctx->newVersion === '') {
            throw new \Exception('升级物料缺少目标版本：' . $ctx->materialsRoot . 'version.php 的 version 字段缺失或为空，请确认升级包完整。');
        }
        $ctx->fromVersion = isset($ctx->siteConfig['douphp_version']) ? (string) $ctx->siteConfig['douphp_version'] : '';
        $ctx->releaseDate = (int) $registry->parseReleaseDate($ctx->fromVersion);
        $ctx->appKey = self::resolveAppKey();
        $ctx->adminRewrite = self::resolveAdminRewrite();
    }

    /**
     * 读取文件级站点密钥：已有非空 DOU_APP_KEY 原样保留，否则生成一次并 define 供本请求后续补丁使用。
     *
     * @return string
     */
    private static function resolveAppKey()
    {
        if (defined('DOU_APP_KEY')) {
            $existing = trim((string) DOU_APP_KEY);
            if ($existing !== '') {
                return $existing;
            }
        }

        $generated = Str::randomHex(32);
        if (!defined('DOU_APP_KEY')) {
            define('DOU_APP_KEY', $generated);
        }

        return $generated;
    }

    /**
     * 读取后台伪静态开关：优先 config.php 真值常量，回退旧 config/system.php 键。
     *
     * 入口会为下游兜底定义 ADMIN_REWRITE 并打 ADMIN_REWRITE_FALLBACK 标记，
     * 凭标记区分：「非同 fallback 的 defined」= config.php 真值，直接采用；
     * fallback（或未定义）时继续回退读旧 config/system.php 的 admin_rewrite 键
     * （老站值迁移链），键不存在才落到兜底值 / false。
     *
     * @return bool
     */
    private static function resolveAdminRewrite()
    {
        if (defined('ADMIN_REWRITE') && !defined('ADMIN_REWRITE_FALLBACK')) {
            return (bool) ADMIN_REWRITE;
        }
        $systemFile = (defined('ROOT_PATH') ? ROOT_PATH : '') . 'config/system.php';
        if (is_file($systemFile)) {
            $systemLoaded = include $systemFile;
            if (is_array($systemLoaded) && isset($systemLoaded['admin_rewrite'])) {
                return (bool) $systemLoaded['admin_rewrite'];
            }
        }
        if (defined('ADMIN_REWRITE')) {
            return (bool) ADMIN_REWRITE;
        }

        return false;
    }
}
