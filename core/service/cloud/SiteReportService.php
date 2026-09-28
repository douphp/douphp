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

namespace Dou\Core\Service\Cloud;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Web\Http\CloudApi;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 站点安装/升级记录上报客户端（匿名基础遥测）。
 *
 * 在站点安装收尾、云端模块/主题/插件/小程序安装与系统升级完成时，把「站点环境快照 + 本次事件」
 * 经 {@see CloudApi} POST 到云端 `POST /report/site`，由 `.api` 落库到 site / site_event 两张表。
 *
 * 设计约束：
 *   - **零 admin 依赖**：仅用 {@see Config} / {@see CloudApi} / ROOT_URL / SYSTEM_SIGN / $_SERVER /
 *     PHP_VERSION / PHP_OS，安装器（install/finish）上下文亦可安全实例化并调用。
 *   - **安装器上下文自愈**：finish 阶段未 bootstrap Config（静态存储为空），{@see ensureCloudConfig()}
 *     按需自加载 `config/cloud.php`，使 `report_site` 开关与 `cloud.api_base` 可用。
 *   - **尽力而为**：整个上报包 try/catch 吞掉一切异常（含 PHP7+ 的 \Error），关闭开关或未配置
 *     api_base 时静默跳过，**绝不阻断安装/升级流程**。
 *   - **隐私**：只采集匿名基础遥测，云账号仅取邮箱/手机（user），绝不携带 password。
 */
class SiteReportService
{
    /**
     * 上报系统级（核心）安装/升级事件。
     *
     * @param string $eventType  install / update / patch
     * @param string $toVersion  目标（安装后）核心版本
     * @param string $fromVersion 升级前核心版本（安装可留空）
     * @return void
     */
    public function reportSystem($eventType, $toVersion, $fromVersion = '')
    {
        $this->send(array(
            'target_type' => 'core',
            'target_slug' => '',
            'target_name' => '',
            'event_type' => (string) $eventType,
            'to_version' => (string) $toVersion,
            'from_version' => (string) $fromVersion,
        ));
    }

    /**
     * 上报扩展（模块/主题/插件/小程序）安装/升级事件。
     *
     * @param string $targetType module / theme / plugin / miniprogram
     * @param string $slug       扩展标识（cloud_id）
     * @param string $eventType  install / update / patch
     * @param string $toVersion  目标版本
     * @param string $name       扩展名称（可空）
     * @return void
     */
    public function reportExtend($targetType, $slug, $eventType, $toVersion, $name = '')
    {
        $this->send(array(
            'target_type' => (string) $targetType,
            'target_slug' => (string) $slug,
            'target_name' => (string) $name,
            'event_type' => (string) $eventType,
            'to_version' => (string) $toVersion,
            'from_version' => '',
        ));
    }

    /**
     * 唯一出口：合并站点快照与事件字段后 POST 到 `PATH_REPORT_SITE`。
     *
     * 关闭开关、未配置 api_base、或采集/发送过程中出现任何异常，均静默返回（void）。
     *
     * @param array<string, mixed> $event
     * @return void
     */
    protected function send(array $event)
    {
        try {
            $this->ensureCloudConfig();

            // 关闭上报：静默跳过。
            if (!Config::get('cloud.report_site', true)) {
                return;
            }
            // 未配置云端地址：CloudApi 亦会返回空 URL，此处提前跳过避免无谓采集。
            if ((string) Config::get('cloud.api_base', '') === '') {
                return;
            }

            $site = $this->collectSite();

            // cms_version 兜底：安装器上下文 site.douphp_version 为空，核心事件用 to_version 填充快照。
            if ((string) $site['cms_version'] === ''
                && isset($event['target_type']) && $event['target_type'] === 'core'
                && !empty($event['to_version'])) {
                $site['cms_version'] = (string) $event['to_version'];
            }

            CloudApi::postJson(CloudApi::PATH_REPORT_SITE, array_merge($site, $event));
        } catch (\Exception $e) {
            // 上报尽力而为：吞掉一切异常，绝不影响安装/升级。
        } catch (\Throwable $e) {
            // PHP 7+ 的 \Error（如容器未绑定 Connection）同样静默吞掉。
        }
    }

    /**
     * 安装器上下文按需自加载 `config/cloud.php`。
     *
     * 已安装站点 Config 早已 bootstrap（api_base 非空），此判断不触发；install/finish 阶段 Config
     * 静态存储为空，则从磁盘补齐 cloud 段，使开关与 api_base 可用。幂等且开销极低。
     *
     * @return void
     */
    protected function ensureCloudConfig()
    {
        if ((string) Config::get('cloud.api_base', '') !== '') {
            return;
        }
        if (!defined('ROOT_PATH')) {
            return;
        }
        $file = ROOT_PATH . 'config/cloud.php';
        if (!is_file($file)) {
            return;
        }
        $config = include($file);
        if (is_array($config)) {
            Config::load($config);
        }
    }

    /**
     * 采集当前站点环境快照（匿名基础遥测）。
     *
     * @return array<string, string>
     */
    protected function collectSite()
    {
        return array(
            'domain' => $this->detectDomain(),
            'site_url' => defined('ROOT_URL') ? (string) ROOT_URL : '',
            'system_sign' => defined('SYSTEM_SIGN') ? (string) SYSTEM_SIGN : '',
            'cms_version' => (string) Config::get('site.douphp_version', ''),
            'theme' => (string) Config::get('site.theme', ''),
            'language' => (string) Config::get('site.language', ''),
            'php_version' => PHP_VERSION,
            'mysql_version' => $this->detectMysqlVersion(),
            'server_software' => isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '',
            'os' => PHP_OS,
            'cloud_account' => $this->detectCloudAccount(),
        );
    }

    /**
     * 解析站点域名（host）：优先 ROOT_URL，回退 HTTP_HOST；去端口、转小写。
     *
     * @return string
     */
    protected function detectDomain()
    {
        $host = '';
        if (defined('ROOT_URL')) {
            $host = (string) parse_url((string) ROOT_URL, PHP_URL_HOST);
        }
        if ($host === '' && isset($_SERVER['HTTP_HOST'])) {
            $host = (string) $_SERVER['HTTP_HOST'];
        }
        if ($host !== '' && strpos($host, ':') !== false) {
            $segments = explode(':', $host);
            $host = (string) $segments[0];
        }

        return strtolower($host);
    }

    /**
     * best-effort 探测 MySQL 版本：容器未绑定 Connection（如安装收尾请求）时返回空串。
     *
     * @return string
     */
    protected function detectMysqlVersion()
    {
        try {
            if (class_exists('\Dou\Core\Facade\DB')) {
                return (string) DB::version();
            }
        } catch (\Exception $e) {
            // 容器未绑定 / 连接不可用时忽略。
        } catch (\Throwable $e) {
            // PHP 7+ 的 \Error 亦忽略。
        }

        return '';
    }

    /**
     * 读取云账号标识（仅邮箱/手机），绝不携带 password。
     *
     * @return string
     */
    protected function detectCloudAccount()
    {
        $raw = (string) Config::get('site.cloud_account', '');
        if ($raw === '') {
            return '';
        }
        $account = unserialize($raw);
        if (is_array($account) && isset($account['user'])) {
            return (string) $account['user'];
        }

        return '';
    }
}
