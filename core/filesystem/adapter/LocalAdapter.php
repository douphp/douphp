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

namespace Dou\Core\Filesystem\Adapter;

use Dou\Core\Filesystem\Contracts\FilesystemAdapter;
use Dou\Core\Filesystem\PathNormalizer;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 本地文件系统驱动：磁盘根 = ROOT_PATH + diskRootRelative。
 */
class LocalAdapter implements FilesystemAdapter
{
    /**
     * 新建目录权限（owner rwx、group/other rx）。
     */
    const DIR_MODE = 0755;

    /**
     * 相对站点根的磁盘根，以 / 结尾（如 images/article/）。
     *
     * @var string
     */
    private $diskRootRelative;

    /**
     * 对外 URL 前缀（可不传，默认 ROOT_URL + diskRootRelative）。
     *
     * @var string
     */
    private $urlPrefix;

    /**
     * 缓存的磁盘根绝对路径（首次访问时计算）。
     *
     * @var string|null
     */
    private $diskRootAbsoluteCache;

    /**
     * @param string $diskRootRelative
     * @param string $urlPrefix
     */
    public function __construct($diskRootRelative, $urlPrefix = '')
    {
        $this->diskRootRelative = PathNormalizer::normalizeRoot($diskRootRelative);
        $this->urlPrefix = (string) $urlPrefix;
        $this->diskRootAbsoluteCache = null;
    }

    /**
     * 相对磁盘 root 路径 → 相对站点根路径。
     *
     * @param string $relativePath
     * @return string
     */
    private function siteRelative($relativePath)
    {
        return $this->diskRootRelative . PathNormalizer::normalizeFilePath($relativePath);
    }

    /**
     * 确保目标文件的父目录存在。
     *
     * @param string $absFile
     * @return bool
     */
    private function ensureParentDirectory($absFile)
    {
        $dir = dirname($absFile);
        if (is_dir($dir)) {
            return true;
        }

        return @mkdir($dir, self::DIR_MODE, true) || is_dir($dir);
    }

    /**
     * 读文件。
     *
     * @param string $path
     * @return string|false
     */
    public function read($path)
    {
        $abs = $this->absolutePath($path);
        if (!file_exists($abs)) {
            return false;
        }

        return @file_get_contents($abs);
    }

    /**
     * 写文件（自动创建目录）。
     *
     * @param string $path
     * @param string $contents
     * @return bool
     */
    public function write($path, $contents)
    {
        $abs = $this->absolutePath($path);
        if (!$this->ensureParentDirectory($abs)) {
            return false;
        }

        return @file_put_contents($abs, $contents) !== false;
    }

    /**
     * 删文件。
     *
     * @param string $path
     * @return bool
     */
    public function delete($path)
    {
        $abs = $this->absolutePath($path);
        if (!file_exists($abs)) {
            return true;
        }

        return @unlink($abs);
    }

    /**
     * 是否存在。
     *
     * @param string $path
     * @return bool
     */
    public function has($path)
    {
        return file_exists($this->absolutePath($path));
    }

    /**
     * 复制。
     *
     * @param string $from
     * @param string $to
     * @return bool
     */
    public function copy($from, $to)
    {
        $absFrom = $this->absolutePath($from);
        $absTo = $this->absolutePath($to);
        if (!$this->ensureParentDirectory($absTo)) {
            return false;
        }

        return @copy($absFrom, $absTo);
    }

    /**
     * 移动 / 重命名。
     *
     * @param string $from
     * @param string $to
     * @return bool
     */
    public function move($from, $to)
    {
        $absFrom = $this->absolutePath($from);
        $absTo = $this->absolutePath($to);
        if (!$this->ensureParentDirectory($absTo)) {
            return false;
        }

        return @rename($absFrom, $absTo);
    }

    /**
     * 文件大小。
     *
     * @param string $path
     * @return int|false
     */
    public function size($path)
    {
        $abs = $this->absolutePath($path);
        if (!file_exists($abs)) {
            return false;
        }
        clearstatcache(true, $abs);

        return @filesize($abs);
    }

    /**
     * 最近修改时间。
     *
     * @param string $path
     * @return int|false
     */
    public function lastModified($path)
    {
        $abs = $this->absolutePath($path);
        if (!file_exists($abs)) {
            return false;
        }
        clearstatcache(true, $abs);

        return @filemtime($abs);
    }

    /**
     * 列目录文件（相对磁盘 root 的路径数组）。
     *
     * @param string $directory
     * @param bool $recursive
     * @return array
     */
    public function listContents($directory, $recursive = false)
    {
        $absDir = $this->absolutePath($directory);
        if (!is_dir($absDir)) {
            return array();
        }

        $relPrefix = PathNormalizer::normalizeSubdir($directory);
        $items = array();
        $handle = @opendir($absDir);
        if ($handle === false) {
            return $items;
        }
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $fullSub = $relPrefix . $entry;
            $absSub = $absDir . '/' . $entry;
            if (is_dir($absSub)) {
                $items[] = $fullSub . '/';
                if ($recursive) {
                    foreach ($this->listContents($fullSub, true) as $deep) {
                        $items[] = $deep;
                    }
                }
            } else {
                $items[] = $fullSub;
            }
        }
        closedir($handle);

        return $items;
    }

    /**
     * 创建目录。
     *
     * @param string $directory
     * @return bool
     */
    public function createDirectory($directory)
    {
        $abs = $this->absolutePath($directory);
        if (is_dir($abs)) {
            return true;
        }

        return @mkdir($abs, self::DIR_MODE, true);
    }

    /**
     * 删除目录（递归）。拒绝空路径 / `.` / 磁盘根本身，避免误清空整盘。
     *
     * @param string $directory
     * @return bool
     */
    public function deleteDirectory($directory)
    {
        $normalized = PathNormalizer::normalizeFilePath($directory);
        if ($normalized === '' || $normalized === '.') {
            throw new \InvalidArgumentException('Refusing to delete disk root directory');
        }

        $abs = $this->absolutePath($directory);
        $diskRootAbs = $this->diskRootAbsolute();
        $absCmp = rtrim(str_replace('\\', '/', $abs), '/');
        $rootCmp = rtrim(str_replace('\\', '/', $diskRootAbs), '/');
        if ($absCmp === $rootCmp) {
            throw new \InvalidArgumentException('Refusing to delete disk root directory');
        }

        if (!is_dir($abs)) {
            return true;
        }

        return $this->recursiveRemove($abs, $diskRootAbs);
    }

    /**
     * 对外 URL：优先磁盘配置的 url 前缀；否则 ROOT_URL + 相对站点根路径。
     *
     * @param string $path
     * @return string
     */
    public function publicUrl($path)
    {
        $rel = PathNormalizer::normalizeFilePath($path);
        if ($this->urlPrefix !== '') {
            return rtrim($this->urlPrefix, '/') . '/' . $rel;
        }

        return PathNormalizer::urlFromSiteRoot($this->siteRelative($path));
    }

    /**
     * 绝对磁盘路径（结果必须落在本磁盘 root 与站点 ROOT_PATH 之内）。
     *
     * @param string $path
     * @return string
     * @throws \InvalidArgumentException 路径穿越或越出磁盘根时抛出
     */
    public function absolutePath($path)
    {
        $abs = PathNormalizer::absoluteFromSiteRoot($this->siteRelative($path));
        PathNormalizer::assertWithin($abs, $this->diskRootAbsolute());

        return $abs;
    }

    /**
     * 本磁盘根的绝对路径（带缓存）。
     *
     * @return string
     */
    private function diskRootAbsolute()
    {
        if ($this->diskRootAbsoluteCache !== null) {
            return $this->diskRootAbsoluteCache;
        }

        $diskRootAbs = rtrim(ROOT_PATH, '/\\');
        if ($this->diskRootRelative !== '') {
            $diskRootAbs .= '/' . rtrim($this->diskRootRelative, '/');
        }
        $this->diskRootAbsoluteCache = $diskRootAbs;

        return $diskRootAbs;
    }

    /**
     * 递归删除一个目录及其内容；任一子项失败则整体失败。
     *
     * 为防御 TOCTOU 竞态，每个子条目在删除前再次验证其 realpath 仍在磁盘根内。
     *
     * @param string $absDir
     * @param string $diskRootAbs 磁盘根绝对路径，用于子条目越界校验
     * @return bool
     */
    private function recursiveRemove($absDir, $diskRootAbs)
    {
        $handle = @opendir($absDir);
        if ($handle === false) {
            return false;
        }
        $ok = true;
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $absDir . '/' . $entry;
            $real = realpath($full);
            if ($real !== false) {
                $normalizedReal = rtrim(str_replace('\\', '/', $real), '/');
                $rootCmp = rtrim(str_replace('\\', '/', $diskRootAbs), '/');
                if ($normalizedReal !== $rootCmp && strpos($normalizedReal . '/', $rootCmp . '/') !== 0) {
                    $ok = false;
                    break;
                }
            }
            if (is_dir($full)) {
                if (!$this->recursiveRemove($full, $diskRootAbs)) {
                    $ok = false;
                    break;
                }
            } elseif (!@unlink($full)) {
                $ok = false;
                break;
            }
        }
        closedir($handle);
        if (!$ok) {
            return false;
        }

        return @rmdir($absDir);
    }
}
