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
 * 全局升级互斥锁（双端统一）：storage/state/upgrade.json。
 *
 * 该文件是「升级运行态记录」，不是「锁文件」：互斥由请求期间持有的 flock 表达，
 * 进程退出（正常返回、网关超时被杀、fatal error、SIGKILL）由内核自动释放，
 * 后来者立即接管，不再依赖 15 分钟时间阈值去猜「进程还在不在」。
 *
 * - 文件常驻，释放时写入 holder 为空的空闲记录；缺失、空文件、脏内容一律视为空闲，
 *   下一次 acquire 自动重建，因此删除该文件不影响正常使用；
 * - 云渠道：preflight 步骤 acquire（holder=install_id），各步骤 touch 续锁，失败/收尾 release；
 * - 向导渠道：run 开头 acquire（holder=wizard），结束/异常 release；
 * - 快照还原：acquire（holder=snapshot:<目录>），1 秒 meta refresh 续跑前 handoff；
 * - 正常步骤之间 handoff 写入 30 秒交接宽限，下一步请求可续锁，其他入口在窗口内被拒。
 *
 * 禁忌：升级运行期间不要删除本文件。持锁进程的 flock 会挂在已删除的 inode 上，
 * 新请求重建文件后可同时加锁（不会卡死，但会短暂失去互斥保护）。
 */
class RunLockService
{
    /** 交接宽限（秒）：handoff 之后其他 holder 需等待的时间 */
    const HANDOFF_SECONDS = 30;

    /** 兜底阈值（秒）：仅用于 flock 完全不可用时放行，以及清扫遗留的旧 upgrade.lock */
    const STALE_SECONDS = 900;

    /** @var string 运行态文件绝对路径 */
    private $file;

    /** @var resource|null 当前持有的 flock 句柄（持有期间常开，进程退出由内核释放） */
    private $handle = null;

    /** @var string 本实例当前持有的 holder（空串表示未持有） */
    private $held = '';

    /**
     * @param string $rootPath 站点根目录（结尾可不带 /）
     */
    public function __construct($rootPath)
    {
        $this->file = rtrim(str_replace('\\', '/', (string) $rootPath), '/') . '/storage/state/upgrade.json';
    }

    /**
     * 获取全局升级锁。
     *
     * @param string $holder 持有者标识
     * @return array array('ok' => bool, 'error' => string)
     */
    public function acquire($holder)
    {
        $holder = (string) $holder;

        // 本实例已持有 flock：直接改写持有者（同 holder 即续锁），不释放句柄
        if ($this->handle !== null) {
            $this->held = $holder;
            $this->write($holder, false);

            return array('ok' => true, 'error' => '');
        }

        $fp = $this->open();
        if ($fp === null) {
            // 无法创建/打开（目录不可写等）：fail-open，与旧版「写失败仍放行」一致
            return array('ok' => true, 'error' => '');
        }

        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            $current = $this->readFrom($fp);
            $age = $this->age($current);
            if ($age !== null && $age >= self::STALE_SECONDS) {
                // flock 不可用环境的兜底：行为与旧版 15 分钟阈值一致
                @fclose($fp);

                return array('ok' => true, 'error' => '');
            }
            $error = $this->busyError($current, $holder);
            @fclose($fp);

            return array('ok' => false, 'error' => $error);
        }

        $current = $this->readFrom($fp);
        $legacyError = $this->legacyGuard($holder);
        if ($legacyError !== '') {
            $this->unlockAndClose($fp);

            return array('ok' => false, 'error' => $legacyError);
        }

        $now = time();
        if (is_array($current)
            && isset($current['holder']) && (string) $current['holder'] !== ''
            && (string) $current['holder'] !== $holder
            && !empty($current['handoff'])
            && isset($current['time'])
            && ($now - (int) $current['time']) < self::HANDOFF_SECONDS) {
            $left = self::HANDOFF_SECONDS - ($now - (int) $current['time']);
            $this->unlockAndClose($fp);

            return array(
                'ok' => false,
                'error' => '上一次升级步骤刚结束，正在交接中（约 ' . $left . ' 秒），请稍候重试。',
            );
        }

        $this->handle = $fp;
        $this->held = $holder;
        $this->write($holder, false, $now);

        return array('ok' => true, 'error' => '');
    }

    /**
     * 刷新持有时间（未持有时内部转为 acquire）。
     *
     * @param string $holder
     * @return array array('ok' => bool, 'error' => string)
     */
    public function touch($holder)
    {
        return $this->acquire((string) $holder);
    }

    /**
     * 步骤交接：写入 30 秒交接宽限后释放 flock，供下一个步骤请求续锁。
     *
     * @param string $holder
     * @return void
     */
    public function handoff($holder)
    {
        if ($this->handle === null || $this->held !== (string) $holder) {
            return;
        }
        $this->write((string) $holder, true);
        $this->closeHandle();
    }

    /**
     * 释放锁：持有者写空闲记录后解锁；非持有者仅在无人持锁且记录匹配时清理。
     *
     * @param string $holder
     * @return void
     */
    public function release($holder)
    {
        $holder = (string) $holder;

        if ($this->handle !== null) {
            if ($this->held === $holder) {
                $this->write('', false);
                $this->closeHandle();
            }

            return;
        }

        if (!is_file($this->file)) {
            // 文件不存在即无锁记录，不因清理型调用凭空创建文件
            return;
        }

        $fp = $this->open();
        if ($fp === null) {
            return;
        }
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            // 有请求正在持锁：非持有者不做任何事
            @fclose($fp);

            return;
        }
        $current = $this->readFrom($fp);
        if (is_array($current) && isset($current['holder']) && (string) $current['holder'] === $holder) {
            $this->writeTo($fp, '');
        }
        $this->unlockAndClose($fp);
    }

    /**
     * 只释放句柄，不改内容（交接状态由 handoff() 写入）。
     */
    public function __destruct()
    {
        $this->closeHandle();
    }

    /**
     * 打开（必要时创建）运行态文件；文件缺失或目录缺失都会自动重建。
     *
     * @return resource|null
     */
    private function open()
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return null;
        }
        $fp = @fopen($this->file, 'c+');

        return $fp === false ? null : $fp;
    }

    /**
     * 从已打开的句柄读取当前记录；空文件/脏内容返回 null（视为空闲）。
     *
     * @param resource $fp
     * @return array|null
     */
    private function readFrom($fp)
    {
        @rewind($fp);
        $raw = @stream_get_contents($fp);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * 写入持有记录（holder 为空串表示空闲）。
     *
     * @param string $holder
     * @param bool $handoff
     * @param int|null $time
     * @return void
     */
    private function write($holder, $handoff, $time = null)
    {
        if ($this->handle === null) {
            return;
        }
        $this->writeTo($this->handle, $holder, $handoff, $time);
    }

    /**
     * @param resource $fp
     * @param string $holder
     * @param bool $handoff
     * @param int|null $time
     * @return void
     */
    private function writeTo($fp, $holder, $handoff = false, $time = null)
    {
        $data = array(
            'holder' => (string) $holder,
            'time' => $time === null ? time() : (int) $time,
            'pid' => (string) $holder === '' ? 0 : $this->pid(),
            'handoff' => (bool) $handoff,
        );
        @rewind($fp);
        @ftruncate($fp, 0);
        @fwrite($fp, json_encode($data));
        @fflush($fp);
    }

    /**
     * 记录的时间戳距今秒数；内容不可读时返回 null（不参与兜底判定）。
     *
     * @param array|null $current
     * @return int|null
     */
    private function age($current)
    {
        if (is_array($current) && isset($current['time']) && (int) $current['time'] > 0) {
            return time() - (int) $current['time'];
        }

        return null;
    }

    /**
     * @param array|null $current
     * @param string $holder
     * @return string
     */
    private function busyError($current, $holder)
    {
        $currentHolder = is_array($current) && isset($current['holder']) ? (string) $current['holder'] : '';
        if ($currentHolder !== '' && $currentHolder === (string) $holder) {
            return '当前升级步骤正在执行中，请稍候再试。';
        }

        return '已有升级任务正在进行（可能来自另一个窗口、手动升级向导或后台仍在执行），请等待其完成或稍后重试。';
    }

    /**
     * 旧版 storage/state/upgrade.lock 的过渡处理（新引擎不再使用它）：
     * - 同一 holder：视为跨版本续跑，直接删除旧锁；
     * - 异 holder 且未超 15 分钟：仍按占用处理（可能旧代码的请求还在跑）；
     * - 超时或不可解析：删除，15 分钟后自然消失。
     *
     * @param string $holder
     * @return string 非空表示需要拒绝的错误文案
     */
    private function legacyGuard($holder)
    {
        $legacy = dirname($this->file) . '/upgrade.lock';
        if (!is_file($legacy)) {
            return '';
        }

        $data = json_decode((string) @file_get_contents($legacy), true);
        $legacyHolder = is_array($data) && isset($data['holder']) ? (string) $data['holder'] : '';
        $legacyTime = is_array($data) && isset($data['time']) ? (int) $data['time'] : (int) @filemtime($legacy);

        if ($legacyHolder !== '' && $legacyHolder === (string) $holder) {
            @unlink($legacy);

            return '';
        }
        if ($legacyTime > 0 && (time() - $legacyTime) < self::STALE_SECONDS) {
            return '已有升级任务正在进行（可能来自另一个窗口、手动升级向导或后台仍在执行），请等待其完成或稍后重试。';
        }
        @unlink($legacy);

        return '';
    }

    /**
     * @return int
     */
    private function pid()
    {
        return function_exists('getmypid') ? (int) getmypid() : 0;
    }

    /**
     * 释放本实例持有的句柄（不改内容）。
     *
     * @return void
     */
    private function closeHandle()
    {
        if ($this->handle !== null) {
            @flock($this->handle, LOCK_UN);
            @fclose($this->handle);
            $this->handle = null;
        }
        $this->held = '';
    }

    /**
     * 释放临时句柄。
     *
     * @param resource $fp
     * @return void
     */
    private function unlockAndClose($fp)
    {
        if (is_resource($fp)) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }
}
