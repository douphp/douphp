<?php

/**
 * DouPHP®
 * ------------------------------------------------------------------------------------
 * Copyright (c) 2013-2026 漳州豆壳网络科技有限公司 (DouCo® Co.,Ltd.)
 *
 * 本软件基于 MIT 协议开源发布，完整协议版本见项目根目录 LICENSE 文件。
 * 网站地址：http://www.douphp.com
 * ------------------------------------------------------------------------------------
 * Author: DouCo Co.,Ltd.
 * Release Date: 2026-10-16
 */

namespace Dou\Core\Service\Upgrade;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 全局升级互斥锁（双端统一）：storage/state/upgrade.lock。
 *
 * 防「两个后台窗口」或「云更新 + 手动向导」并发升级同一站点（整包覆盖 + 改库不可并发）。
 * - 云渠道：preflight 步骤 acquire（holder=install_id），各步骤 touch 刷新，失败/收尾 release；
 * - 向导渠道：run 开头 acquire（holder=wizard），结束/异常 release；
 * - 持锁者长时间无刷新（STALE_SECONDS）视为陈旧锁，可被后来者接管（防中断后永久卡死）。
 */
class RunLockService
{
    /** 距最后一次刷新的陈旧阈值（秒）：超过即视为废弃锁，可接管 */
    const STALE_SECONDS = 900;

    /** @var string 锁文件绝对路径 */
    private $lockFile;

    /**
     * @param string $rootPath 站点根目录（结尾带 /）
     */
    public function __construct($rootPath)
    {
        $this->lockFile = rtrim(str_replace('\\', '/', (string) $rootPath), '/') . '/storage/state/upgrade.lock';
    }

    /**
     * 获取全局升级锁。
     *
     * @param string $holder 持有者标识（云渠道=install_id；向导=wizard）
     * @return array array('ok' => bool, 'error' => string)
     */
    public function acquire($holder)
    {
        $holder = (string) $holder;
        $current = $this->read();
        if (is_array($current)
            && isset($current['holder']) && (string) $current['holder'] !== '' && (string) $current['holder'] !== $holder
            && isset($current['time']) && (time() - (int) $current['time']) < self::STALE_SECONDS) {
            return array(
                'ok' => false,
                'error' => '已有升级任务正在进行（可能来自另一个窗口或手动升级向导），请等待其完成或稍后重试。',
            );
        }
        $this->write($holder);

        return array('ok' => true, 'error' => '');
    }

    /**
     * 刷新锁时间（同持有者；长流程的步骤边界调用，防被误判陈旧）。
     *
     * @param string $holder
     * @return void
     */
    public function touch($holder)
    {
        $current = $this->read();
        if (is_array($current) && isset($current['holder']) && (string) $current['holder'] === (string) $holder) {
            $this->write($holder);
        }
    }

    /**
     * 释放锁（仅持有者可释放；非持有者调用为无操作）。
     *
     * @param string $holder
     * @return void
     */
    public function release($holder)
    {
        $current = $this->read();
        if (is_array($current) && isset($current['holder']) && (string) $current['holder'] === (string) $holder) {
            @unlink($this->lockFile);
        }
    }

    /**
     * @return array|null
     */
    private function read()
    {
        if (!is_file($this->lockFile)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($this->lockFile), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param string $holder
     * @return void
     */
    private function write($holder)
    {
        $dir = dirname($this->lockFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($this->lockFile, json_encode(array(
            'holder' => (string) $holder,
            'time' => time(),
            'pid' => function_exists('getmypid') ? getmypid() : 0,
        )), LOCK_EX);
    }
}
