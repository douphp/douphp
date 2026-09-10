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

namespace Dou\Core\Filesystem;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 站点根相对路径与绝对路径规范化工具。
 *
 * 主存储层与 Attachment 域共用：负责拼接、反斜杠归一、首尾斜杠处理，
 * 并拒绝 `..` / NUL / ADS 流等路径穿越形态。
 */
class PathNormalizer
{
    /**
     * 断言相对路径不含穿越或绝对盘符形态。
     *
     * 拒绝以下危险形态：
     *   - NUL 字节（\0）
     *   - Windows 绝对盘符（C:/ 等）
     *   - UNC 网络路径（//server/...）
     *   - 路径穿越（..）
     *   - Windows ADS 流（file.php:stream）
     *
     * @param string $path
     * @return void
     * @throws \InvalidArgumentException
     */
    public static function assertSafeRelative($path)
    {
        $p = (string) $path;
        if ($p === '') {
            return;
        }
        if (strpos($p, "\0") !== false) {
            throw new \InvalidArgumentException('Path contains NUL byte');
        }

        $normalized = str_replace('\\', '/', $p);

        // Windows ADS 流检测：路径中包含冒号但不是盘符形式
        if (strpos($normalized, ':') !== false) {
            if (!preg_match('/^[a-zA-Z]:/', $normalized)) {
                throw new \InvalidArgumentException('Path contains illegal character: colon');
            }
        }

        if (preg_match('/^[a-zA-Z]:/', $normalized) || strpos($normalized, '//') === 0) {
            throw new \InvalidArgumentException('Absolute path not allowed: ' . $path);
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw new \InvalidArgumentException('Path traversal not allowed: ' . $path);
            }
        }
    }

    /**
     * 断言文件名是单段名（无目录分隔、非 `.` / `..`）。
     *
     * @param string $name
     * @return void
     * @throws \InvalidArgumentException
     */
    public static function assertSafeFileName($name)
    {
        $name = (string) $name;
        if ($name === '') {
            throw new \InvalidArgumentException('Empty file name');
        }
        if (strpos($name, "\0") !== false) {
            throw new \InvalidArgumentException('File name contains NUL byte');
        }
        if (strpos($name, '/') !== false || strpos($name, '\\') !== false) {
            throw new \InvalidArgumentException('File name must not contain path separators: ' . $name);
        }
        if ($name === '.' || $name === '..') {
            throw new \InvalidArgumentException('Invalid file name: ' . $name);
        }
        if (strpos($name, ':') !== false) {
            throw new \InvalidArgumentException('File name contains illegal character: colon');
        }
    }

    /**
     * 断言绝对路径落在给定根目录之内。
     *
     * 已存在的路径用 realpath；尚不存在时沿父目录向上找最近可解析节点，
     * 再拼剩余相对段后做前缀比较，避免中间目录为越狱 symlink 时仅靠逻辑前缀放行。
     *
     * @param string $absolute
     * @param string $rootAbsolute
     * @return void
     * @throws \InvalidArgumentException
     */
    public static function assertWithin($absolute, $rootAbsolute)
    {
        $root = self::resolveExistingPath($rootAbsolute);
        if ($root === null) {
            // 根尚未落盘：退回逻辑形态，仍拒绝明显越界字符串。
            $root = rtrim(str_replace('\\', '/', (string) $rootAbsolute), '/');
            $abs = self::resolveLogicalAbsolute($absolute);
        } else {
            $abs = self::resolveAbsoluteForJail($absolute);
        }

        if ($abs !== $root && strpos($abs . '/', $root . '/') !== 0) {
            throw new \InvalidArgumentException('Path escapes filesystem root: ' . $absolute);
        }
    }

    /**
     * 解析已存在路径的 realpath（统一为正斜杠、无尾斜杠）；不存在返回 null。
     *
     * @param string $path
     * @return string|null
     */
    private static function resolveExistingPath($path)
    {
        $real = realpath($path);
        if ($real === false) {
            return null;
        }

        return rtrim(str_replace('\\', '/', $real), '/');
    }

    /**
     * 将绝对路径解析为用于 jail 比较的形态：已存在走 realpath；否则解析最近父目录 + 剩余段。
     *
     * @param string $absolute
     * @return string
     * @throws \InvalidArgumentException
     */
    private static function resolveAbsoluteForJail($absolute)
    {
        $existing = self::resolveExistingPath($absolute);
        if ($existing !== null) {
            return $existing;
        }

        $normalized = str_replace('\\', '/', (string) $absolute);
        $normalized = rtrim($normalized, '/');
        $cursor = $normalized;
        $suffix = array();

        while ($cursor !== '' && $cursor !== '/' && !preg_match('/^[a-zA-Z]:$/', $cursor)) {
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $suffix[] = basename($cursor);
            $parentReal = self::resolveExistingPath($parent);
            if ($parentReal !== null) {
                $suffix = array_reverse($suffix);
                $resolved = $parentReal;
                foreach ($suffix as $segment) {
                    if ($segment === '' || $segment === '.') {
                        continue;
                    }
                    if ($segment === '..') {
                        throw new \InvalidArgumentException('Path traversal not allowed: ' . $absolute);
                    }
                    $resolved .= '/' . $segment;
                }

                return $resolved;
            }
            $cursor = str_replace('\\', '/', $parent);
        }

        throw new \InvalidArgumentException('Unable to resolve path: ' . $absolute);
    }

    /**
     * 根不存在时的逻辑绝对路径（仅字符串归一）。
     *
     * @param string $absolute
     * @return string
     */
    private static function resolveLogicalAbsolute($absolute)
    {
        return rtrim(str_replace('\\', '/', (string) $absolute), '/');
    }

    /**
     * 把任意输入规范成「相对站点根、以 / 结尾」的目录形态；空目录返回 ''。
     *
     * @param string $path
     * @return string
     */
    public static function normalizeRoot($path)
    {
        self::assertSafeRelative($path);
        $p = str_replace('\\', '/', (string) $path);
        $p = trim($p, '/');
        if ($p === '') {
            return '';
        }

        return $p . '/';
    }

    /**
     * 子目录规范：以 / 结尾或空。
     *
     * @param string $path
     * @return string
     */
    public static function normalizeSubdir($path)
    {
        self::assertSafeRelative($path);
        $p = str_replace('\\', '/', (string) $path);
        if ($p === '') {
            return '';
        }

        return trim($p, '/') . '/';
    }

    /**
     * 文件相对路径规范：去掉首部 /，反斜杠归一。
     *
     * @param string $path
     * @return string
     */
    public static function normalizeFilePath($path)
    {
        self::assertSafeRelative($path);

        return ltrim(str_replace('\\', '/', (string) $path), '/');
    }

    /**
     * 把「相对站点根的路径」转成绝对磁盘路径。
     *
     * @param string $relative
     * @return string
     */
    public static function absoluteFromSiteRoot($relative)
    {
        $rel = self::normalizeFilePath($relative);
        $abs = rtrim(ROOT_PATH, '/\\') . '/' . $rel;
        self::assertWithin($abs, rtrim(ROOT_PATH, '/\\'));

        return $abs;
    }

    /**
     * 把「相对站点根的路径」转成对外 URL（含 ROOT_URL 前缀）。
     *
     * @param string $relative
     * @return string
     */
    public static function urlFromSiteRoot($relative)
    {
        $rel = self::normalizeFilePath($relative);

        return rtrim(ROOT_URL, '/') . '/' . $rel;
    }

    /**
     * 把目录 + 文件名拼接为相对路径，处理斜杠。
     *
     * @param string $directory
     * @param string $name
     * @return string
     */
    public static function joinDirectory($directory, $name)
    {
        self::assertSafeRelative($directory);
        self::assertSafeFileName($name);

        $dir = trim(str_replace('\\', '/', (string) $directory), '/');
        $name = (string) $name;
        if ($dir === '') {
            return $name;
        }

        return $dir . '/' . $name;
    }
}
