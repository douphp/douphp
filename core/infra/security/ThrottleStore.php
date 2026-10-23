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
     * @param string $dir 存储目录（如 storage/state/throttle/）
     */
    public function __construct($dir)
    {
        $this->dir = rtrim((string) $dir, "/\\") . DIRECTORY_SEPARATOR;
    }

    /**
     * 当前窗口内自增一次并返回计数；窗口到期则重开窗口。
     *
     * 读改写全程持有排他锁，避免并发首次请求同时读到空文件而绕过 nonce 去重。
     *
     * @param string $key
     * @param int $window 窗口秒数
     * @return int 自增后的命中数
     */
    public function hit($key, $window)
    {
        $window = max(1, (int) $window);
        $result = $this->withLock($key, function ($data) use ($window) {
            $now = time();
            if (!is_array($data) || !isset($data['count'], $data['reset']) || $now >= (int) $data['reset']) {
                $data = array('count' => 0, 'reset' => $now + $window);
            }
            $data['count'] = (int) $data['count'] + 1;

            return array($data, (int) $data['count']);
        });

        return $result === null ? 1 : (int) $result;
    }

    /**
     * 原子计数：未达到上限则自增并返回 true；达到上限返回 false（不自增）。
     *
     * 供分钟限流使用，替代 tooMany() + hit() 的非原子组合。
     *
     * @param string $key
     * @param int $max
     * @param int $window
     * @return bool
     */
    public function attempt($key, $max, $window)
    {
        $max = (int) $max;
        if ($max <= 0) {
            return true;
        }
        $window = max(1, (int) $window);
        $result = $this->withLock($key, function ($data) use ($max, $window) {
            $now = time();
            if (!is_array($data) || !isset($data['count'], $data['reset']) || $now >= (int) $data['reset']) {
                $data = array('count' => 0, 'reset' => $now + $window);
            }
            if ((int) $data['count'] >= $max) {
                return array($data, false);
            }
            $data['count'] = (int) $data['count'] + 1;

            return array($data, true);
        });

        return $result === null ? true : (bool) $result;
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
     * 在文件排他锁内执行读改写。
     *
     * 回调返回 array($newData, $result)；$newData 为数组时写回文件。
     * 打开/加锁失败返回 null，由调用方保持原有 fail-open 语义。
     *
     * @param string $key
     * @param callable $callback
     * @return mixed|null
     */
    private function withLock($key, $callback)
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0777, true);
        }
        $handle = @fopen($this->file($key), 'c+');
        if ($handle === false) {
            return null;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return null;
            }
            rewind($handle);
            $raw = stream_get_contents($handle);
            $data = null;
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }

            $out = $callback($data);
            $newData = (is_array($out) && array_key_exists(0, $out)) ? $out[0] : null;
            $result = (is_array($out) && array_key_exists(1, $out)) ? $out[1] : null;

            if (is_array($newData)) {
                $payload = json_encode($newData);
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, $payload === false ? '' : $payload);
                fflush($handle);
            }

            return $result;
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }
}
