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

use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 磁盘契约。
 *
 * 单磁盘视图：所有 path 都是「相对磁盘 root」的路径；不感知 ROOT_PATH 或具体驱动差异。
 * 业务层若需操作 dou_file 表记录、解析 `.file` 号、画廊等，转 AttachmentService。
 */
interface Filesystem
{
    /**
     * 写入字符串内容到指定相对路径。
     *
     * @param string $path
     * @param string $contents
     * @return bool
     */
    public function put($path, $contents);

    /**
     * 把 UploadedFile 写入磁盘目录，自动生成基于随机串的文件名。
     *
     * @param string $directory 相对磁盘 root 的目录（'' 表示磁盘根）
     * @param UploadedFile $file
     * @return string|false 相对磁盘 root 的最终路径（含目录与文件名），失败 false
     */
    public function putFile($directory, UploadedFile $file);

    /**
     * 把 UploadedFile 写入磁盘目录，使用指定文件名（含或不含扩展名都支持）。
     *
     * @param string $directory
     * @param UploadedFile $file
     * @param string $name 文件名；不含扩展名时自动附加上传文件的扩展名
     * @return string|false
     */
    public function putFileAs($directory, UploadedFile $file, $name);

    /**
     * 读取相对路径的文本内容。
     *
     * @param string $path
     * @return string|false
     */
    public function get($path);

    /**
     * 是否存在。
     *
     * @param string $path
     * @return bool
     */
    public function exists($path);

    /**
     * 删除文件。
     *
     * @param string $path
     * @return bool
     */
    public function delete($path);

    /**
     * 复制。
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
     * 文件大小（字节）。
     *
     * @param string $path
     * @return int|false
     */
    public function size($path);

    /**
     * 最近修改时间戳。
     *
     * @param string $path
     * @return int|false
     */
    public function lastModified($path);

    /**
     * 对外可访问 URL（本地驱动拼 ROOT_URL；远端驱动返回 CDN/签名地址）。
     *
     * @param string $path
     * @return string
     */
    public function url($path);

    /**
     * 绝对路径（本地驱动返回 ROOT_PATH + 磁盘 root + path；远端驱动可能返回 '' 或抛错）。
     *
     * @param string $path
     * @return string
     */
    public function path($path);

    /**
     * 创建目录。
     *
     * @param string $directory
     * @return bool
     */
    public function makeDirectory($directory);

    /**
     * 删除目录（含其内全部内容）。
     *
     * @param string $directory
     * @return bool
     */
    public function deleteDirectory($directory);

    /**
     * 列出目录下的文件。
     *
     * @param string $directory
     * @param bool $recursive
     * @return array
     */
    public function files($directory, $recursive = false);

    /**
     * 列出目录下的子目录。
     *
     * @param string $directory
     * @return array
     */
    public function directories($directory);

    /**
     * 读取磁盘配置项（root / url / upload_max_kb / allow_extensions / image_quality / thumb_directory 等）。
     *
     * @param string|null $key null 时返回整组配置
     * @param mixed $default
     * @return mixed
     */
    public function getConfig($key = null, $default = null);
}
