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

namespace Dou\Core\Filesystem\Contracts;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 文件系统驱动契约。
 *
 * 所有 path 都是「相对磁盘 root」。驱动实现负责把相对路径映射到具体后端。
 */
interface FilesystemAdapter
{
    /**
     * 读取文件内容。
     *
     * @param string $path
     * @return string|false
     */
    public function read($path);

    /**
     * 写入文件内容。
     *
     * @param string $path
     * @param string $contents
     * @return bool
     */
    public function write($path, $contents);

    /**
     * 删除文件。
     *
     * @param string $path
     * @return bool
     */
    public function delete($path);

    /**
     * 文件是否存在。
     *
     * @param string $path
     * @return bool
     */
    public function has($path);

    /**
     * 复制文件。
     *
     * @param string $from
     * @param string $to
     * @return bool
     */
    public function copy($from, $to);

    /**
     * 移动 / 重命名。
     *
     * @param string $from
     * @param string $to
     * @return bool
     */
    public function move($from, $to);

    /**
     * 文件大小。
     *
     * @param string $path
     * @return int|false
     */
    public function size($path);

    /**
     * 最近修改时间。
     *
     * @param string $path
     * @return int|false
     */
    public function lastModified($path);

    /**
     * 列出目录内容（返回相对路径的字符串数组）。
     *
     * @param string $directory
     * @param bool $recursive
     * @return array
     */
    public function listContents($directory, $recursive = false);

    /**
     * 创建目录。
     *
     * @param string $directory
     * @return bool
     */
    public function createDirectory($directory);

    /**
     * 删除目录（含全部内容）。
     *
     * @param string $directory
     * @return bool
     */
    public function deleteDirectory($directory);

    /**
     * 对外可访问 URL（本地驱动 ROOT_URL+path；云驱动 CDN/签名）。
     *
     * @param string $path
     * @return string
     */
    public function publicUrl($path);

    /**
     * 绝对路径（本地驱动 ROOT_PATH+path；云驱动可不实现/抛错）。
     *
     * @param string $path
     * @return string
     */
    public function absolutePath($path);
}
