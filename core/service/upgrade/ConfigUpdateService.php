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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 升级期间的"数据库配置改写"集合（双端统一）：
 *   - buildDbConfigRestoreSql()：组装 dou_config 配置回灌 SQL（不含版本号与 update_date；
 *     版本号刻意留到流程末尾写，半途失败时站点仍显示旧版本、可被检测与重试）
 *   - buildDbConfigVersionSql()：组装版本号回写 SQL
 *   - finalizeUpdateDate()：update_date 全流程权威写点（写后读回校验，失败即抛错）
 *
 * 配置文件（config/*.php）的合并与落盘由 lib/ConfigMerge.php（Dou\Upgrade\Lib\ConfigMerge）统一负责
 * （云载荷 _update/action.php 与向导 UpgradeController 经 Runner 同源调用），本类不参与文件写入。
 *
 * SQL 实际执行（fnExecute）等"底层操作工具"由 Runner 串起来；本类只负责"组装"。
 * 数据库相关辅助（escapeString 等）统一走 DB:: 静态门面（Connection 已由外层装配绑定到容器）。
 */
class ConfigUpdateService
{
    /**
     * 组装 dou_config 配置回灌 SQL（不含 update_date 与版本号）。
     *
     * 该段在「config 表重建返回后」会被立即执行一次（早回灌，消除"纯默认配置"窗口），
     * 并在流程末尾随完整 up_sql 再执行一次（幂等）；版本号刻意不包含在内：
     * 半途失败时站点仍显示升级前的旧版本，可被云端/后台检测并允许重试。
     *
     * @param array  $cfg        当前 dou_config（key => value）
     * @param string $prefix     表前缀
     * @param string $rootUrl    用于 hash_code 计算与 domain 回填
     * @param string $dbhost
     * @param string $dbname
     * @param string $dbuser
     * @param string $dbpass
     * @return string
     */
    public function buildDbConfigRestoreSql(array $cfg, $prefix, $rootUrl, $dbhost, $dbname, $dbuser, $dbpass)
    {
        $sql = '';
        foreach ($cfg as $key => $value) {
            if ($key === 'update_date') {
                continue;
            }
            $sql .= "UPDATE `" . $prefix . "config` SET value = '" . DB::escapeString((string) $value) . "' WHERE name = '" . DB::escapeString((string) $key) . "';\r\n";
        }

        // 没有 hash_code 时补一份
        if (empty($cfg['hash_code'])) {
            $hash_code = md5(md5(time()) . md5(md5($rootUrl . $dbhost . $dbname . $dbuser . $dbpass)));
            $sql .= "UPDATE `" . $prefix . "config` SET value = '" . $hash_code . "' WHERE name = 'hash_code';\r\n";
        }

        // 旧站没有 domain（或值为空）时，回填为当前站点 root_url
        // （重建会从 config.sql 灌入占位默认值，本 UPDATE 晚于重建执行，天然覆盖）
        if (empty($cfg['domain']) && $rootUrl !== '') {
            $sql .= "UPDATE `" . $prefix . "config` SET value = '" . DB::escapeString((string) $rootUrl) . "' WHERE name = 'domain';\r\n";
        }

        // 语言箱清空（早期升级路径需要）
        $sql .= "UPDATE `" . $prefix . "config` SET box = '' WHERE name = 'language';\r\n";

        return $sql;
    }

    /**
     * 组装版本号回写 SQL（仅 douphp_version）。
     *
     * 版本号只在流程末尾写：半途失败时站点仍显示旧版本，避免"看起来已升级"。
     *
     * @param string $prefix     表前缀
     * @param string $newVersion 升级后写入的版本号字符串
     * @return string
     */
    public function buildDbConfigVersionSql($prefix, $newVersion)
    {
        return "UPDATE `" . $prefix . "config` SET value = '" . DB::escapeString($newVersion) . "' WHERE name = 'douphp_version';\r\n";
    }

    /**
     * update_date 全流程权威写点（早写安全网 + 末尾复写，两次调用，幂等）。
     *
     * 调用时机：
     *   1) config 表重建刚返回时（早写）：立即把账本从"重建结果/默认值"修正为真值，此后即使
     *      进程中断，下次升级读到的 boot 快照仍是真实账本，不会发生"重试污染"；
     *   2) 结构重建、补丁迁移、up_sql 全量回写（fnExecute）全部完成之后（末尾复写）：
     *      吸收所有中途写入后的最终真值。
     *
     * 算法：读"当前库"update_date 真值，与 boot 快照的全部非 system 类别键做并集
     * （module / theme / plugin / miniprogram 等；以库为准，快照仅补回库中缺失的键，绝不删除既有键；
     * 标量类别库中整体缺失时同样按快照补回），仅改写 system.update/patch 为新版本日期，仅移除已退役
     * 模块 data/box/fragment，单次直连 UPDATE 落库，并做写后读回校验（不一致即抛错）。
     *
     * 该设计彻底消除"重建回灌 / up_sql 合并回写"通道之间的快照/顺序竞态（退役迁移已不再直写
     * update_date，旧的三通道竞态已整体解耦）：无论中途哪条通道把库值回退成 config.sql 默认值，boot
     * 快照（升级前真实账本）都能把缺失的键并回来；@unserialize 失败时的降级同样保留 boot 快照键，
     * 绝不退化为硬编码 article/product。
     *
     * @param array  $bootSnapshot 升级启动时读取的 dou_config 全量快照（含真实 update_date）
     * @param string $prefix       表前缀
     * @param string $newVersion   升级后版本号串（末 8 位作系统更新日期）
     * @return string 落库的 update_date 序列化串
     * @throws \Exception 写后读回校验失败（SQL 失败 / 转义 / 列截断等）时抛出，不让升级静默留在"账本未落库"状态
     */
    public function finalizeUpdateDate(array $bootSnapshot, $prefix, $newVersion)
    {
        $date = substr(trim($newVersion), -8);

        // 当前库真值（重建 + 迁移 + fnExecute 之后）
        $liveRaw = (string) DB::getOne('SELECT `value` FROM `' . $prefix . "config` WHERE `name` = 'update_date'");
        $liveArr = @unserialize($liveRaw);
        if (!is_array($liveArr)) {
            $liveArr = array();
        }
        // boot 快照真值（升级前真实账本，早于任何重建读取）
        $bootRaw = isset($bootSnapshot['update_date']) ? (string) $bootSnapshot['update_date'] : '';
        $bootArr = @unserialize($bootRaw);
        if (!is_array($bootArr)) {
            $bootArr = array();
        }

        $result = $liveArr;
        // 非 system 类别（module / theme / plugin / miniprogram 等）：以库为准，快照补回库中缺失的键
        // （绝不删除既有键）；标量类别（如自定义扩展键）库中整体缺失时按快照补回。
        foreach ($bootArr as $classKey => $bootClass) {
            if ($classKey === 'system') {
                continue;
            }
            if (!is_array($bootClass)) {
                if (!array_key_exists($classKey, $result)) {
                    $result[$classKey] = $bootClass;
                }
                continue;
            }
            $liveClass = (isset($result[$classKey]) && is_array($result[$classKey])) ? $result[$classKey] : array();
            $merged = $liveClass;
            foreach ($bootClass as $itemKey => $itemDate) {
                if (!array_key_exists($itemKey, $merged)) {
                    $merged[$itemKey] = $itemDate;
                }
            }
            if (!empty($merged)) {
                $result[$classKey] = $merged;
            }
        }

        // system：保留既有项，仅把 update/patch 写为新版本日期
        $system = (isset($result['system']) && is_array($result['system'])) ? $result['system'] : array();
        $system['update'] = $date;
        $system['patch'] = $date;
        $result['system'] = $system;

        // 移除已退役模块日期（退役集合与数据退役迁移一致，幂等）
        if (isset($result['module']) && is_array($result['module'])) {
            unset($result['module']['data'], $result['module']['box'], $result['module']['fragment']);
        }

        $newRaw = serialize($result);
        DB::query('UPDATE `' . $prefix . "config` SET `value` = '" . DB::escapeString($newRaw) . "' WHERE `name` = 'update_date'");

        // 写后读回校验：SQL 失败 / 转义 / 列截断等任何静默失败都必须显式暴露，
        // 不允许把升级留在"账本未可靠落库"的状态。
        $verifyRaw = (string) DB::getOne('SELECT `value` FROM `' . $prefix . "config` WHERE `name` = 'update_date'");
        $verifyArr = @unserialize($verifyRaw);
        foreach ($result as $classKey => $classVal) {
            if ($classKey === 'system' || !is_array($classVal)) {
                continue;
            }
            $expectKeys = array_keys($classVal);
            $verifyKeys = (is_array($verifyArr) && isset($verifyArr[$classKey]) && is_array($verifyArr[$classKey]))
                ? array_keys($verifyArr[$classKey]) : array();
            sort($expectKeys);
            sort($verifyKeys);
            if (!is_array($verifyArr) || $expectKeys !== $verifyKeys) {
                throw new \Exception('update_date 权威写点写后校验失败（' . $classKey . ' 键集合不一致），'
                    . '可能因 SQL 失败 / 转义 / 列截断导致账本未可靠落库，升级已中止，请修复后重试。');
            }
        }
        $verifyDate = (is_array($verifyArr) && isset($verifyArr['system']['update']))
            ? (string) $verifyArr['system']['update'] : null;
        if ($verifyDate !== (string) $date) {
            throw new \Exception('update_date 权威写点写后校验失败（system.update 不一致），'
                . '可能因 SQL 失败 / 转义 / 列截断导致账本未可靠落库，升级已中止，请修复后重试。');
        }

        return $newRaw;
    }
}
