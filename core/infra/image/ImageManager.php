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

namespace Dou\Core\Infra\Image;

use Dou\Core\Infra\Image\Driver\GdDriver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 图像处理管理器：暴露 {@see ImageEditor} 链式入口与底层 {@see GdDriver} 直调入口。
 *
 * 业务侧用法：
 *   Image::open($abs)->resize(200, 0)->save($dstAbs, $quality);
 *   Image::driver()->resize($src, $dst, 200, 0, $quality);
 *
 * 与 {@see \Dou\Core\Filesystem\FilesystemManager} 一样在容器中注册为单例，
 * 由 {@see \Dou\Core\Facade\Image} 静态门面解析。
 */
class ImageManager
{
    /** @var GdDriver|null */
    private $defaultDriver;

    /**
     * 取默认驱动（GD）。
     *
     * @return GdDriver
     */
    public function driver()
    {
        if ($this->defaultDriver === null) {
            $this->defaultDriver = new GdDriver();
        }

        return $this->defaultDriver;
    }

    /**
     * 打开一张图（链式 API 入口）。
     *
     * @param string $absolutePath
     * @return ImageEditor
     */
    public function open($absolutePath)
    {
        return new ImageEditor($this->driver(), $absolutePath);
    }

    /**
     * 读取图片元信息（width / height / type / name / ext）。
     *
     * @param string $absolutePath
     * @return array|false
     */
    public function info($absolutePath)
    {
        return $this->driver()->info($absolutePath);
    }

    /**
     * 直接调 driver 的 resize（业务侧常用）。
     *
     * @param string $srcAbs
     * @param string $dstAbs
     * @param int $width
     * @param int $height
     * @param int $quality
     * @return bool
     */
    public function resize($srcAbs, $dstAbs, $width, $height, $quality = 90)
    {
        return $this->driver()->resize($srcAbs, $dstAbs, $width, $height, $quality);
    }

    /**
     * 按目标路径扩展名转码（可顺带限宽）。
     *
     * @param string $srcAbs
     * @param string $dstAbs
     * @param int $width
     * @param int $height
     * @param int $quality
     * @return bool
     */
    public function transcode($srcAbs, $dstAbs, $width = 0, $height = 0, $quality = 90)
    {
        return $this->driver()->transcode($srcAbs, $dstAbs, $width, $height, $quality);
    }

    /**
     * 写缩略图（绝对路径）。
     *
     * @param string $srcAbs
     * @param string $thumbAbs
     * @param int $width
     * @param int $height
     * @param int $quality
     * @return bool
     */
    public function thumb($srcAbs, $thumbAbs, $width, $height, $quality = 90)
    {
        return $this->driver()->thumb($srcAbs, $thumbAbs, $width, $height, $quality);
    }

    /**
     * 加水印。
     *
     * @param string $srcAbs
     * @param string $dstAbs
     * @param array $options 见 {@see GdDriver::watermark()}
     * @param int $quality
     * @return bool
     */
    public function watermark($srcAbs, $dstAbs, array $options, $quality = 90)
    {
        return $this->driver()->watermark($srcAbs, $dstAbs, $options, $quality);
    }

    /**
     * 给定源相对路径与「缩略图相对子目录」，按 `*_thumb` 文件名生成缩略图相对路径。
     *
     * @param string $sourceRelative 例如 `images/article/1.jpg`
     * @param string $thumbSubdir 例如 `thumb/`（空则与原图同目录）
     * @return string 例如 `images/article/thumb/1_thumb.jpg`
     */
    public function buildThumbPath($sourceRelative, $thumbSubdir = '')
    {
        $name = basename($sourceRelative);
        $dot = strrpos($name, '.');
        $base = $dot !== false ? substr($name, 0, $dot) : $name;
        $ext = $dot !== false ? substr($name, $dot + 1) : '';
        $thumbName = $base . '_thumb' . ($ext !== '' ? '.' . $ext : '');
        $dir = dirname($sourceRelative);
        $dir = $dir === '.' || $dir === '' ? '' : trim(str_replace('\\', '/', $dir), '/') . '/';
        $sub = $thumbSubdir === '' ? '' : trim(str_replace('\\', '/', $thumbSubdir), '/') . '/';

        return $dir . $sub . $thumbName;
    }
}
