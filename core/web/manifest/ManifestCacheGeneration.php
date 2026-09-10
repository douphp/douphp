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

namespace Dou\Core\Web\Manifest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * Web 端 lang_js / routes_js 的 URL 缓存世代（与内容指纹分离）。
 *
 * 管理员清空缓存时 bump，使 HTML 中 ?v={contentHash}-{generation} 变化，强制浏览器打破
 * immutable 本地缓存并回源；磁盘文件名与 ETag / 小程序 lang_v 仍仅用内容 hash。
 */
class ManifestCacheGeneration
{
    /**
     * @return int
     */
    public static function current()
    {
        $file = self::filePath();
        if (!is_file($file)) {
            return 0;
        }
        $raw = trim((string) @file_get_contents($file));
        if ($raw === '' || !ctype_digit($raw)) {
            return 0;
        }

        return (int) $raw;
    }

    /**
     * 清空缓存链路调用：世代 +1 并落盘。
     *
     * @return int 新世代
     */
    public static function bump()
    {
        $next = self::current() + 1;
        $file = self::filePath();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($file, (string) $next, LOCK_EX);

        return $next;
    }

    /**
     * 拼入 HTML script src 的 ?v= 查询参数（含清空世代）。
     *
     * @param string $contentHash 内容指纹（manifestHash）
     * @return string
     */
    public static function urlVersion($contentHash)
    {
        $generation = self::current();
        if ($generation <= 0) {
            return (string) $contentHash;
        }

        return (string) $contentHash . '-' . $generation;
    }

    /**
     * @return string
     */
    private static function filePath()
    {
        $base = defined('STORAGE_PATH') ? STORAGE_PATH : (defined('ROOT_PATH') ? ROOT_PATH . 'storage/' : '');

        return $base . 'cache' . DIRECTORY_SEPARATOR . 'manifest_generation.txt';
    }
}
