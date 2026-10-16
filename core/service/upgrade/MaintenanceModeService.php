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
use Dou\Core\Foundation\Configuration\Config;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 维护模式（还原期间站点临时关闭）。
 *
 * 复用系统既有 `site_closed` 开关（前台 / API 拦截，后台放行），并把「开启前的原值」
 * 持久化到 storage/state/maintenance.json：还原完成 / 中止后按原值恢复，避免
 * 「还原任务中断 → 站点永久关闭」的死锁；后台首页横幅提示 + 还原点页面提供手动恢复入口。
 *
 * 兼容 PHP 5.6+。
 */
class MaintenanceModeService
{
    /** @var string 站点根（结尾带 /） */
    private $rootPath;

    /** @var string 状态文件绝对路径 */
    private $stateFile;

    /**
     * @param string $rootPath 站点根目录
     */
    public function __construct($rootPath)
    {
        $this->rootPath = rtrim(str_replace('\\', '/', (string) $rootPath), '/') . '/';
        $this->stateFile = $this->rootPath . 'storage/state/maintenance.json';
    }

    /**
     * 开启维护模式（幂等：已开启时保留最初的「原值」，不覆盖）。
     *
     * @param string $reason 触发原因（如 restore:snapshot 目录名）
     * @return array array('ok' => bool, 'was_closed' => bool, 'error' => string)
     */
    public function enable($reason = '')
    {
        $state = $this->state();
        if ($state === null) {
            $wasClosed = (bool) Config::get('site.site_closed', false);
            $state = array(
                'was_closed' => $wasClosed,
                'since' => time(),
                'reason' => (string) $reason,
            );
            $dir = dirname($this->stateFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            if (@file_put_contents($this->stateFile, json_encode($state), LOCK_EX) === false) {
                return array('ok' => false, 'was_closed' => $wasClosed, 'error' => '无法写入维护状态文件');
            }
        }

        $this->setSiteClosed(true);

        return array('ok' => true, 'was_closed' => !empty($state['was_closed']), 'error' => '');
    }

    /**
     * 恢复维护模式开启前的 site_closed 原值（幂等）。
     *
     * @return void
     */
    public function restore()
    {
        $state = $this->state();
        if ($state === null) {
            return;
        }
        $this->setSiteClosed(!empty($state['was_closed']));
        @unlink($this->stateFile);
    }

    /**
     * 维护状态（不存在时返回 null）。
     *
     * @return array|null array('was_closed' => bool, 'since' => int, 'reason' => string)
     */
    public function state()
    {
        if (!is_file($this->stateFile)) {
            return null;
        }
        $state = json_decode((string) @file_get_contents($this->stateFile), true);

        return is_array($state) ? $state : null;
    }

    /**
     * 是否存在由还原流程开启的维护模式。
     *
     * @return bool
     */
    public function isActive()
    {
        return $this->state() !== null;
    }

    /**
     * 写 site_closed（DB + 本请求内 Config 同步）。
     *
     * @param bool $closed
     * @return void
     */
    protected function setSiteClosed($closed)
    {
        $value = $closed ? '1' : '0';
        try {
            DB::table('config')->where('name', 'site_closed')->update(array('value' => $value));
        } catch (\Exception $e) {
            // 无 DB 连接时（极端场景）不阻断：状态文件仍是恢复依据
        } catch (\Throwable $e) {
            // PHP 7+ 的 \Error 同样吞掉
        }
        Config::set('site.site_closed', $closed ? 1 : 0);
    }
}
