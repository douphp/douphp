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
 * Release Date: 2026-09-09
 */

namespace Dou\Admin\Service\Ai;

use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * AI 批量生成短时幂等保护。
 */
class BatchRequestGuard extends BaseService
{
    /** @var string */
    private $directory;

    /** @var int */
    private $ttl;

    /**
     * @param string|null $directory
     * @param int $ttl
     */
    public function __construct($directory = null, $ttl = 30)
    {
        $this->directory = $directory === null
            ? STORAGE_PATH . 'cache/ai_batch_guard/'
            : rtrim((string) $directory, '/\\') . DIRECTORY_SEPARATOR;
        $this->ttl = max(1, (int) $ttl);
    }

    /**
     * @param string $fingerprint
     * @return bool
     */
    public function acquire($fingerprint)
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
            return false;
        }

        $path = $this->path($fingerprint);
        if (is_file($path) && (filemtime($path) + $this->ttl) <= time()) {
            @unlink($path);
        }

        $handle = @fopen($path, 'x');
        if (!$handle) {
            return false;
        }
        fwrite($handle, (string) time());
        fclose($handle);

        return true;
    }

    /**
     * @param string $fingerprint
     * @return void
     */
    public function forget($fingerprint)
    {
        $path = $this->path($fingerprint);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param string $fingerprint
     * @return string
     */
    private function path($fingerprint)
    {
        return $this->directory . hash('sha256', (string) $fingerprint) . '.lock';
    }
}
