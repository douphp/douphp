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

namespace Dou\Core\Infra\Security;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 文件后端的限流计数存储。
 *
 * 每个限流键落一个 JSON 文件 `<dir>/<md5(key)>.json`，结构 `{ "count": int, "reset": ts }`。
 * 计数窗口到期（now >= reset）后自动视为新窗口，无需外部清理；clear() 用于命中后清零，
 * purgeExpired() 提供机会式过期清理。
 *
 * 仅作「定向限流」的轻量计数器，不追求分布式精确；并发以 LOCK_EX 写入收敛。
 */
class ThrottleStore
{
    /** @var string 存储目录（结尾含分隔符） */
    private $dir;

    /**
     * @param string $dir 存储目录（如 data/cache/throttle/）
     */
    public function __construct($dir)
    {
        $this->dir = rtrim((string) $dir, "/\\") . DIRECTORY_SEPARATOR;
    }

    /**
     * 当前窗口内自增一次并返回计数；窗口到期则重开窗口。
     *
     * @param string $key
     * @param int $window 窗口秒数
     * @return int 自增后的命中数
     */
    public function hit($key, $window)
    {
        $now = time();
        $data = $this->read($key);
        if ($data === null || !isset($data['reset']) || $now >= (int) $data['reset']) {
            $data = array('count' => 0, 'reset' => $now + (int) $window);
        }
        $data['count'] = (int) $data['count'] + 1;
        $this->write($key, $data);

        return (int) $data['count'];
    }

    /**
     * 当前窗口内是否已达到上限（不自增）。
     *
     * @param string $key
     * @param int $max
     * @param int $window
     * @return bool
     */
    public function tooMany($key, $max, $window)
    {
        $now = time();
        $data = $this->read($key);
        if ($data === null || !isset($data['reset']) || $now >= (int) $data['reset']) {
            return false;
        }

        return (int) $data['count'] >= (int) $max;
    }

    /**
     * 距当前窗口重置还剩多少秒（已过期返回 0）。
     *
     * @param string $key
     * @return int
     */
    public function availableIn($key)
    {
        $data = $this->read($key);
        if ($data === null || !isset($data['reset'])) {
            return 0;
        }
        $remain = (int) $data['reset'] - time();

        return $remain > 0 ? $remain : 0;
    }

    /**
     * 清除某个键的计数。
     *
     * @param string $key
     * @return void
     */
    public function clear($key)
    {
        $file = $this->file($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * 机会式清理已过期的计数文件。
     *
     * @return void
     */
    public function purgeExpired()
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $now = time();
        $files = glob($this->dir . '*.json');
        if (!is_array($files)) {
            return;
        }
        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false || $raw === '') {
                continue;
            }
            $data = @json_decode($raw, true);
            if (!is_array($data) || !isset($data['reset']) || $now >= (int) $data['reset']) {
                @unlink($file);
            }
        }
    }

    /**
     * @param string $key
     * @return string
     */
    private function file($key)
    {
        return $this->dir . md5((string) $key) . '.json';
    }

    /**
     * @param string $key
     * @return array|null
     */
    private function read($key)
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = @json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param string $key
     * @param array $data
     * @return void
     */
    private function write($key, array $data)
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0777, true);
        }
        @file_put_contents($this->file($key), json_encode($data), LOCK_EX);
    }
}
