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
 * Release Date: 2026-11-16
 */

namespace Dou\Core\Service\System;

use Dou\Core\Infra\Security\ThrottleStore;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 运行时临时文件及过期计数清理，后台首页每日触发。
 */
class TempCleanupService
{
    /** @var string */
    private $storagePath;

    /** @var resource|null */
    private static $usageLock;

    /**
     * 初始化存储目录。
     *
     * @param string $storagePath 存储根目录
     */
    public function __construct($storagePath)
    {
        $this->storagePath = rtrim(str_replace('\\', '/', $storagePath), '/') . '/';
    }

    /**
     * 持有临时文件使用锁，直到请求结束。
     *
     * @return bool 是否取得共享锁
     */
    public static function holdUsageLock()
    {
        if (is_resource(self::$usageLock)) {
            return true;
        }
        $service = new self(STORAGE_PATH);
        $handle = $service->openLock();
        if (!$handle) {
            return false;
        }
        if (!@flock($handle, LOCK_SH)) {
            fclose($handle);
            return false;
        }
        // 构建器返回后调用方还要读取文件，不能在构建完成时提前释放。
        self::$usageLock = $handle;
        return true;
    }

    /**
     * 到期后取得独占锁并执行一次白名单清理。
     *
     * @return array|null 清理统计；未到期或无法取得锁返回 null
     */
    public function runScheduled()
    {
        $stateDir = $this->storagePath . 'state';
        if (is_dir($stateDir) && !$this->isRealDirectory($stateDir)) {
            return null;
        }
        $marker = $stateDir . '/tmp_cleanup.json';
        if (!$this->isDue($marker)) {
            return null;
        }
        $handle = $this->openLock();
        if (!$handle) {
            return null;
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        try {
            if (!$this->isDue($marker)) {
                return null;
            }
            $result = array('deleted' => 0, 'failed' => 0, 'unknown' => 0);
            $now = time();
            $this->cleanDirectory('tmp', 'snapshot', 86400, $now, $result);
            $this->cleanDirectory('tmp/aigc', 'aigc', 86400, $now, $result);
            $this->cleanDirectory('tmp/aop/logs', 'log', 30 * 86400, $now, $result);
            foreach (array('throttle', 'open', 'geo') as $name) {
                $dir = $stateDir . '/' . $name;
                if ($this->isRealDirectory($dir)) {
                    $result['deleted'] += (new ThrottleStore($dir))->purgeExpired();
                }
            }
            if ($result['failed'] || $result['unknown']) {
                error_log('[tmp cleanup] ' . json_encode($result));
            }
            if (!is_link($marker)) {
                @file_put_contents($marker, json_encode(array('last_run' => $now, 'result' => $result)), LOCK_EX);
            }
            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 判断是否距上次扫描满一天。
     *
     * @param string $marker 水位文件
     * @return bool 是否到期
     */
    private function isDue($marker)
    {
        if (!is_file($marker) || is_link($marker)) {
            return true;
        }
        $data = json_decode((string) @file_get_contents($marker), true);
        return !is_array($data) || empty($data['last_run']) || time() - (int) $data['last_run'] >= 86400;
    }

    /**
     * 打开常驻锁文件，不删除或替换锁文件。
     *
     * @return resource|false 文件句柄
     */
    private function openLock()
    {
        $dir = $this->storagePath . 'state';
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return false;
        }
        $file = $dir . '/tmp_cleanup.lock';
        if (!$this->isRealDirectory($dir) || is_link($file)) {
            return false;
        }
        return @fopen($file, 'c');
    }

    /**
     * 检查目录没有通过链接重定向到其他位置。
     *
     * @param string $dir 目录路径
     * @return bool 是否为预期目录
     */
    private function isRealDirectory($dir)
    {
        $real = realpath($dir);
        if ($real === false || is_link($dir)) {
            return false;
        }
        $real = str_replace('\\', '/', $real);
        $expected = rtrim($dir, '/');
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($real, $expected) === 0 : $real === $expected;
    }

    /**
     * 仅删除指定类别中已过期的普通文件。
     *
     * @param string $relative 相对存储目录
     * @param string $type 文件类别
     * @param int $keepSeconds 留存秒数
     * @param int $now 当前时间
     * @param array $result 清理统计
     * @return void 无返回值
     */
    private function cleanDirectory($relative, $type, $keepSeconds, $now, array &$result)
    {
        $dir = $this->storagePath . $relative;
        if (!file_exists($dir)) {
            return;
        }
        if (!$this->isRealDirectory($dir)) {
            $result['unknown']++;
            return;
        }
        $names = @scandir($dir);
        if ($names === false) {
            $result['failed']++;
            return;
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $file = $dir . '/' . $name;
            if ($type === 'snapshot' && in_array($name, array('aop', 'aigc'), true)
                && $this->isRealDirectory($file)) {
                continue;
            }
            $matched = false;
            if ($type === 'snapshot') {
                $matched = preg_match('/^snapshot-(system|module|theme|plugin)-[A-Za-z0-9_\-]{1,60}-\d{14}(?:-[a-f0-9]{16})?\.zip$/D', $name);
            } elseif ($type === 'aigc') {
                $matched = preg_match('/^(ppt_\d{14}_[a-f0-9]{8}\.pptx|xlsx_\d{14}_[a-f0-9]{8}\.xlsx)$/D', $name);
            } else {
                $matched = preg_match('/^(?:aop_comm_err_[A-Za-z0-9_\-]+|alipay_business_(?:alipay|alipaywap|alipayf2f))_(\d{4}-\d{2}-\d{2})\.log$/D', $name, $parts);
                if ($matched) {
                    $date = strtotime($parts[1] . ' 00:00:00');
                    if ($date === false || date('Y-m-d', $date) !== $parts[1]) {
                        $matched = false;
                    } elseif ($now - $date < $keepSeconds) {
                        continue;
                    }
                }
            }
            // 业务日志由共享日志组件自行按日维护，不属于未知残留。
            if ($type === 'log' && $name === '.business_cleanup' && !is_link($file) && is_file($file)) {
                continue;
            }
            if (!$matched || is_link($file) || !is_file($file)) {
                $result['unknown']++;
                continue;
            }
            clearstatcache(true, $file);
            $modified = @filemtime($file);
            if ($modified !== false && $now - $modified >= $keepSeconds) {
                if (@unlink($file)) {
                    $result['deleted']++;
                } else {
                    $result['failed']++;
                }
            }
        }
    }
}
