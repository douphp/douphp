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

namespace Dou\Admin\Service\Cloud;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 云端扩展状态载荷与更新时间服务。
 */
class UpdateStateService extends BaseService
{
    /**
     * 云端/更新用的站点侧序列化载荷（urlencode(serialize(...))）。
     *
     * @param string $type 子类型：theme、module、miniprogram、plugin 等；空为全部 update_date
     * @return string
     */
    public function localSitePayload($type = '')
    {
        $updateDate = unserialize(Config::get('site.update_date', ''));
        $cloudAccount = CloudService::loadCloudAccount();

        if ($type) {
            $localSite = isset($updateDate[$type]) ? $updateDate[$type] : array();

            if ($type === 'theme') {
                $localSite['module'] = isset($updateDate['module']) ? $updateDate['module'] : array();
            }
        } else {
            $localSite = is_array($updateDate) ? $updateDate : array();
        }

        $localSite['cloud_account'] = array(
            'user' => isset($cloudAccount['user']) ? $cloudAccount['user'] : '',
            'password' => isset($cloudAccount['password']) ? $cloudAccount['password'] : '',
        );
        $localSite['url'] = ROOT_URL;
        $localSite['system_sign'] = SYSTEM_SIGN;

        return urlencode(serialize($localSite));
    }

    /**
     * 云端/更新用的系统环境序列化载荷（urlencode(serialize(...))）。
     *
     * @return string
     */
    public function localSystemPayload()
    {
        $updateDate = Config::get('site.update_date', '') !== '' ? unserialize(Config::get('site.update_date', '')) : array();
        if (!is_array($updateDate)) {
            $updateDate = array();
        }

        $systemInfo = isset($updateDate['system']) && is_array($updateDate['system']) ? $updateDate['system'] : array();

        $system = array(
            'ver' => Config::get('site.douphp_version', ''),
            'update' => isset($systemInfo['update']) ? $systemInfo['update'] : 0,
            'patch' => isset($systemInfo['patch']) ? $systemInfo['patch'] : 0,
            'lang' => Config::get('site.language', ''),
            'php_ver' => PHP_VERSION,
            'mysql_ver' => DB::version(),
            'os' => PHP_OS,
            'web_server' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '',
            'charset' => strtoupper(DOU_CHARSET),
            'template' => Config::get('site.site_theme', ''),
            'url' => ROOT_URL,
            'ip' => isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '',
            'system_sign' => SYSTEM_SIGN,
        );

        return urlencode(serialize($system));
    }

    /**
     * 写入扩展/系统更新日期。
     *
     * @param string $type 扩展类型（theme|module|plugin|miniprogram|system）
     * @param string $cloudId 云端资源 id（system 类型时为版本字符串）
     * @param bool $del 是否删除该记录
     * @param string $mode 系统模式（仅 type=system 时区分 update / patch）
     * @return void
     */
    public function changeUpdateDate($type, $cloudId, $del = false, $mode = '')
    {
        $raw = DB::table('config')->where('name', 'update_date')->value('value');
        $updateDate = $raw !== '' ? unserialize($raw) : array();
        if (!is_array($updateDate)) {
            $updateDate = array();
        }

        if ($del) {
            unset($updateDate[$type][$cloudId]);
        } else {
            if ($type === 'system') {
                $date = substr(trim($cloudId), -8);
                $updateDate['system'][$mode] = $date;
            } else {
                $updateDate[$type][$cloudId] = date('Ymd', time());
            }
        }

        DB::table('config')
            ->where('name', 'update_date')
            ->update(array('value' => serialize($updateDate)));
    }

    /**
     * 记录模块包版本（T6）：dou_config.module_version 行 = 序列化 map（模块标识 => 包版本）。
     *
     * 用途：模块升级载荷读已装版本做「相等短路 / 降级拒绝」，成功后写新版本；
     * 行不存在（老站首跳）时自动 INSERT。
     *
     * @param string $moduleId 模块标识
     * @param string $version 包版本（打包日 Ymd）
     * @return void
     */
    public function recordModuleVersion($moduleId, $version)
    {
        $moduleId = (string) $moduleId;
        $version = (string) $version;
        if ($moduleId === '' || $version === '') {
            return;
        }

        $raw = DB::table('config')->where('name', 'module_version')->value('value');
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
     * 移除模块包版本记录（T6：模块卸载 / clearModule 清理时调用）。
     *
     * @param string $moduleId
     * @return void
     */
    public function removeModuleVersion($moduleId)
    {
        $moduleId = (string) $moduleId;
        if ($moduleId === '') {
            return;
        }

        $raw = DB::table('config')->where('name', 'module_version')->value('value');
        $map = (is_string($raw) && $raw !== '') ? @unserialize($raw) : array();
        if (!is_array($map) || !isset($map[$moduleId])) {
            return;
        }
        unset($map[$moduleId]);

        DB::table('config')
            ->where('name', 'module_version')
            ->update(array('value' => serialize($map)));
    }

    /**
     * 读取模块升级前的版本账本快照（module_version + update_date）。
     *
     * 模块还原点不再整表备份 config，创建快照时先把这两个键固化到 manifest，
     * 还原收尾时由 {@see restoreModuleState()} 精确写回。
     *
     * @param string $moduleId 模块标识（dou_config.module_version 的键）
     * @param string $cloudId 云端资源 id（dou_config.update_date.module 的键）
     * @return array array('module_id','cloud_id','version','update_date')；缺失值均为空串
     */
    public function moduleSnapshotState($moduleId, $cloudId)
    {
        $moduleId = (string) $moduleId;
        $cloudId = (string) $cloudId;
        $state = array(
            'module_id' => $moduleId,
            'cloud_id' => $cloudId,
            'version' => '',
            'update_date' => '',
        );

        $raw = DB::table('config')->where('name', 'module_version')->value('value');
        $map = (is_string($raw) && $raw !== '') ? @unserialize($raw) : array();
        if (is_array($map) && $moduleId !== '' && isset($map[$moduleId])) {
            $state['version'] = (string) $map[$moduleId];
        }

        $raw = DB::table('config')->where('name', 'update_date')->value('value');
        $map = (is_string($raw) && $raw !== '') ? @unserialize($raw) : array();
        if (is_array($map) && $cloudId !== ''
            && isset($map['module']) && is_array($map['module'])
            && isset($map['module'][$cloudId])) {
            $state['update_date'] = (string) $map['module'][$cloudId];
        }

        return $state;
    }

    /**
     * 还原模块升级前的版本账本：精确写回 / 删除对应键，保留其它模块与其它 type。
     *
     * 空串表示「升级前键不存在」；键存在但值为空的场景不在版本账本语义内。
     *
     * @param array $state {@see moduleSnapshotState()} 的返回值
     * @return void
     */
    public function restoreModuleState(array $state)
    {
        $moduleId = isset($state['module_id']) ? (string) $state['module_id'] : '';
        $cloudId = isset($state['cloud_id']) ? (string) $state['cloud_id'] : '';
        $version = isset($state['version']) ? (string) $state['version'] : '';
        $updateDate = isset($state['update_date']) ? (string) $state['update_date'] : '';

        if ($moduleId !== '') {
            $raw = DB::table('config')->where('name', 'module_version')->value('value');
            if (!is_string($raw) || $raw === '') {
                // 账本行不存在：仅当历史版本非空时补建，避免为老站凭空写入空 map
                if ($version !== '') {
                    $this->recordModuleVersion($moduleId, $version);
                }
            } else {
                $map = @unserialize($raw);
                if (!is_array($map)) {
                    $map = array();
                }
                if ($version === '') {
                    unset($map[$moduleId]);
                } else {
                    $map[$moduleId] = $version;
                }

                DB::table('config')
                    ->where('name', 'module_version')
                    ->update(array('value' => serialize($map)));
            }
        }

        if ($cloudId !== '') {
            $raw = DB::table('config')->where('name', 'update_date')->value('value');
            if (is_string($raw) && $raw !== '') {
                $map = @unserialize($raw);
                if (!is_array($map)) {
                    $map = array();
                }

                if ($updateDate === '') {
                    if (isset($map['module']) && is_array($map['module'])) {
                        unset($map['module'][$cloudId]);
                        if (empty($map['module'])) {
                            unset($map['module']);
                        }
                    }
                } else {
                    if (!isset($map['module']) || !is_array($map['module'])) {
                        $map['module'] = array();
                    }
                    $map['module'][$cloudId] = $updateDate;
                }

                DB::table('config')
                    ->where('name', 'update_date')
                    ->update(array('value' => serialize($map)));
            }
        }
    }
}
