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

use Dou\Core\Filesystem\Contracts\Filesystem;
use Dou\Core\Filesystem\Contracts\FilesystemAdapter;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 磁盘视图：把 {@see FilesystemAdapter} 包装为 {@see Filesystem} 契约实例。
 *
 * 业务侧通过 `Storage::disk('article')` 拿到本类实例，然后链式调 put / get / url / delete 等。
 * 带扩展名/大小校验的 HTTP 上传请走 AttachmentService（或 Validator），勿直接依赖 putFile 做门禁。
 */
class Disk implements Filesystem
{
    /**
     * 扩展名最大长度限制（防御性）。
     */
    const MAX_EXTENSION_LENGTH = 20;

    /**
     * 底层驱动。
     *
     * @var FilesystemAdapter
     */
    private $adapter;

    /**
     * 磁盘配置：root / url / upload_max_kb / allow_extensions / image_quality / thumb_directory 等。
     *
     * @var array
     */
    private $config;

    /**
     * @param FilesystemAdapter $adapter
     * @param array $config
     */
    public function __construct(FilesystemAdapter $adapter, array $config)
    {
        $this->adapter = $adapter;
        $this->config = $config;
    }

    /**
     * 统一异常捕获：把 InvalidArgumentException（路径越界/穿越）转为安全返回值。
     *
     * @param callable $fn
     * @param mixed $fallback
     * @return mixed
     */
    private function safeCall($fn, $fallback = false)
    {
        try {
            return call_user_func($fn);
        } catch (\InvalidArgumentException $e) {
            return $fallback;
        }
    }

    /**
     * 写入字符串。
     *
     * @param string $path
     * @param string $contents
     * @return bool
     */
    public function put($path, $contents)
    {
        return $this->safeCall(function () use ($path, $contents) {
            return $this->adapter->write($path, $contents);
        });
    }

    /**
     * 用 UploadedFile 落盘，自动生成随机文件名（保留上传扩展名）。
     *
     * 本方法不做 allow_extensions / upload_max_kb 校验；上传门禁请走 Attachment 或 Validator。
     *
     * @param string $directory
     * @param UploadedFile $file
     * @return string|false 相对磁盘 root 的路径
     */
    public function putFile($directory, UploadedFile $file)
    {
        $ext = $this->sanitizeExtension($file->getClientOriginalExtension());
        $base = $this->randomBasename();
        $name = $ext !== '' ? $base . '.' . $ext : $base;

        return $this->putFileAs($directory, $file, $name);
    }

    /**
     * 用 UploadedFile 落盘，使用指定文件名。
     *
     * @param string $directory
     * @param UploadedFile $file
     * @param string $name
     * @return string|false
     */
    public function putFileAs($directory, UploadedFile $file, $name)
    {
        $name = (string) $name;
        if ($name === '') {
            return false;
        }

        if (strpos($name, '.') === false) {
            $ext = $this->sanitizeExtension($file->getClientOriginalExtension());
            if ($ext !== '') {
                $name .= '.' . $ext;
            }
        }

        $relativePath = $this->safeCall(function () use ($directory, $name) {
            return PathNormalizer::joinDirectory($directory, $name);
        });
        if ($relativePath === false) {
            return false;
        }

        $absoluteTarget = $this->adapter->absolutePath($relativePath);
        $parentRel = dirname($relativePath);
        if ($parentRel === '.' || $parentRel === '') {
            $parentRel = '';
        }
        if (!$this->adapter->createDirectory($parentRel) && !is_dir(dirname($absoluteTarget))) {
            return false;
        }

        if (!$file->move(dirname($absoluteTarget), basename($absoluteTarget))) {
            return false;
        }

        return $relativePath;
    }

    /**
     * 读取文件内容。
     *
     * @param string $path
     * @return string|false
     */
    public function get($path)
    {
        return $this->safeCall(function () use ($path) {
            return $this->adapter->read($path);
        });
    }

    /**
     * 是否存在。
     *
     * @param string $path
     * @return bool
     */
    public function exists($path)
    {
        return $this->safeCall(function () use ($path) {
            return $this->adapter->has($path);
        });
    }

    /**
     * 删除文件。
     *
     * @param string $path
     * @return bool
     */
    public function delete($path)
    {
        return $this->safeCall(function () use ($path) {
            return $this->adapter->delete($path);
        });
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
        return $this->safeCall(function () use ($from, $to) {
            return $this->adapter->copy($from, $to);
        });
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
        return $this->safeCall(function () use ($from, $to) {
            return $this->adapter->move($from, $to);
        });
    }

    /**
     * 大小。
     *
     * @param string $path
     * @return int|false
     */
    public function size($path)
    {
        return $this->safeCall(function () use ($path) {
            return $this->adapter->size($path);
        });
    }

    /**
     * 最近修改时间。
     *
     * @param string $path
     * @return int|false
     */
    public function lastModified($path)
    {
        return $this->safeCall(function () use ($path) {
            return $this->adapter->lastModified($path);
        });
    }

    /**
     * 对外 URL。
     *
     * @param string $path
     * @return string
     * @throws \InvalidArgumentException
     */
    public function url($path)
    {
        return $this->adapter->publicUrl($path);
    }

    /**
     * 绝对磁盘路径。
     *
     * @param string $path
     * @return string
     * @throws \InvalidArgumentException
     */
    public function path($path)
    {
        return $this->adapter->absolutePath($path);
    }

    /**
     * 创建目录。
     *
     * @param string $directory
     * @return bool
     */
    public function makeDirectory($directory)
    {
        return $this->safeCall(function () use ($directory) {
            return $this->adapter->createDirectory($directory);
        });
    }

    /**
     * 递归删除目录。
     *
     * @param string $directory
     * @return bool
     */
    public function deleteDirectory($directory)
    {
        return $this->safeCall(function () use ($directory) {
            return $this->adapter->deleteDirectory($directory);
        });
    }

    /**
     * 列文件（剔除子目录）。
     *
     * @param string $directory
     * @param bool $recursive
     * @return array
     */
    public function files($directory, $recursive = false)
    {
        $items = $this->safeCall(function () use ($directory, $recursive) {
            return $this->adapter->listContents($directory, $recursive);
        }, array());

        $files = array();
        foreach ($items as $entry) {
            if (substr($entry, -1) !== '/') {
                $files[] = $entry;
            }
        }

        return $files;
    }

    /**
     * 列子目录。
     *
     * @param string $directory
     * @return array
     */
    public function directories($directory)
    {
        $items = $this->safeCall(function () use ($directory) {
            return $this->adapter->listContents($directory, false);
        }, array());

        $dirs = array();
        foreach ($items as $entry) {
            if (substr($entry, -1) === '/') {
                $dirs[] = rtrim($entry, '/');
            }
        }

        return $dirs;
    }

    /**
     * 读取磁盘配置项。
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function getConfig($key = null, $default = null)
    {
        if ($key === null) {
            return $this->config;
        }
        if (array_key_exists($key, $this->config)) {
            return $this->config[$key];
        }

        return $default;
    }

    /**
     * 取底层驱动（特殊场景用，业务勿滥用）。
     *
     * @return FilesystemAdapter
     */
    public function getAdapter()
    {
        return $this->adapter;
    }

    /**
     * 生成加密安全的随机文件名（不含扩展名）。
     *
     * 使用 random_bytes 生成 8 位十六进制串，碰撞概率约 1/2^32，
     * 远优于原 mt_rand 方案（1/9000）。
     *
     * @return string
     */
    private function randomBasename()
    {
        return bin2hex(random_bytes(4));
    }

    /**
     * 安全化扩展名：仅保留字母数字，限制长度。
     *
     * @param string $ext
     * @return string
     */
    private function sanitizeExtension($ext)
    {
        $ext = strtolower(trim((string) $ext));
        if ($ext === '' || strlen($ext) > self::MAX_EXTENSION_LENGTH || !ctype_alnum($ext)) {
            return '';
        }

        return $ext;
    }
}
