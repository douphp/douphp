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
        $cloudAccount = unserialize(Config::get('site.cloud_account', ''));

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
}
