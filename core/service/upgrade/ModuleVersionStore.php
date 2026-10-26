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
 * Release Date: 2026-10-25
 */

namespace Dou\Core\Service\Upgrade;

use Dou\Core\Facade\DB;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 模块版本账本（dou_config.module_version）唯一读写入口。
 *
 * 账本值为序列化 map（模块标识 => 包版本 Ymd），供模块升级做相等短路 / 降级拒绝与
 * 成功后落版本；安装流程、升级 Runner、快照还原与卸载清理共用本类。
 */
class ModuleVersionStore
{
    /**
     * 读取某模块的已装版本。
     *
     * @param string $moduleId 模块标识
     * @return string 版本号；未安装 / 无记录返回空串
     */
    public function get($moduleId)
    {
        $moduleId = (string) $moduleId;
        if ($moduleId === '') {
            return '';
        }

        $raw = $this->rawValue();
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        $map = @unserialize($raw);
        if (!is_array($map) || !isset($map[$moduleId])) {
            return '';
        }

        return (string) $map[$moduleId];
    }

    /**
     * 写入某模块的已装版本（行不存在时自动 INSERT）。
     *
     * @param string $moduleId 模块标识
     * @param string $version 包版本
     * @return void
     */
    public function record($moduleId, $version)
    {
        $moduleId = (string) $moduleId;
        $version = (string) $version;
        if ($moduleId === '' || $version === '') {
            return;
        }

        $raw = $this->rawValue();
        $map = (is_string($raw) && $raw !== '') ? @unserialize($raw) : array();
        if (!is_array($map)) {
            $map = array();
        }
        $map[$moduleId] = $version;

        if (!is_string($raw) || $raw === '') {
            // 行不存在（老站首跳）：INSERT
            DB::query('INSERT INTO `' . DB::getPrefix() . "config` (`value`, `name`, `type`, `box`, `tab`)"
                . " VALUES ('" . DB::escapeString(serialize($map)) . "', 'module_version', 'hidden', '', '')");
            return;
        }

        DB::table('config')
            ->where('name', 'module_version')
            ->update(array('value' => serialize($map)));
    }

    /**
     * 移除某模块的已装版本（行 / 键不存在时静默返回）。
     *
     * @param string $moduleId 模块标识
     * @return void
     */
    public function remove($moduleId)
    {
        $moduleId = (string) $moduleId;
        if ($moduleId === '') {
            return;
        }

        $raw = $this->rawValue();
        if (!is_string($raw) || $raw === '') {
            return;
        }
        $map = @unserialize($raw);
        if (!is_array($map) || !isset($map[$moduleId])) {
            return;
        }
        unset($map[$moduleId]);

        DB::table('config')
            ->where('name', 'module_version')
            ->update(array('value' => serialize($map)));
    }

    /**
     * 读取账本原值。
     *
     * @return string|null
     */
    private function rawValue()
    {
        return DB::table('config')->where('name', 'module_version')->value('value');
    }
}
