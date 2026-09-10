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

namespace Dou\Core\Infra\Image\Driver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * GD 图像驱动。
 *
 * 仅本地文件系统使用；参数采用绝对路径。上层 {@see \Dou\Core\Infra\Image\ImageEditor} 提供链式 API。
 */
class GdDriver
{
    /**
     * 读取图片元信息（width / height / type / name / ext）。
     *
     * @param string $absolutePath
     * @return array|false
     */
    public function info($absolutePath)
    {
        if (!is_file($absolutePath)) {
            return false;
        }
        $size = @getimagesize($absolutePath);
        if (!is_array($size)) {
            return false;
        }

        return array(
            'width' => $size[0],
            'height' => $size[1],
            'type' => $size[2],
            'name' => basename($absolutePath),
            'ext' => pathinfo($absolutePath, PATHINFO_EXTENSION),
        );
    }

    /**
     * 调整尺寸 / 等比缩放（width 或 height 任一可为 0 自动计算）。
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
        $info = $this->info($srcAbs);
        if (!$info) {
            return false;
        }
        $srcImage = $this->loadImage($srcAbs, $info['type']);
        if (!$srcImage) {
            return false;
        }

        if (!$width && $info['width'] > $width) {
            $width = ($info['width'] / $info['height']) * $height;
        }
        if (!$height) {
            // 宽度等比模式（attachment imageWidth 压缩）：原图已不超目标宽则跳过，只缩不放
            if ($info['width'] <= $width) {
                return true;
            }
            $height = ($info['height'] / $info['width']) * $width;
        }
        $width = (int) round($width);
        $height = (int) round($height);
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $dstImage = $this->createCanvas($width, $height, $info['type']);
        if (function_exists('imagecopyresampled')) {
            imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $width, $height, $info['width'], $info['height']);
        } else {
            imagecopyresized($dstImage, $srcImage, 0, 0, 0, 0, $width, $height, $info['width'], $info['height']);
        }

        $this->ensureDir(dirname($dstAbs));
        if (file_exists($dstAbs)) {
            @unlink($dstAbs);
        }
        $ok = $this->writeImage($dstImage, $dstAbs, $info['type'], $quality);

        if (PHP_VERSION_ID < 80000) {
            imagedestroy($dstImage);
            imagedestroy($srcImage);
        }

        return $ok;
    }

    /**
     * 写缩略图：在 $thumbAbs 写入 `${base}_thumb.${ext}`。
     *
     * @param string $srcAbs
     * @param string $thumbAbs 目标绝对路径（含 `_thumb` 文件名）
     * @param int $width
     * @param int $height
     * @param int $quality
     * @return bool
     */
    public function thumb($srcAbs, $thumbAbs, $width, $height, $quality = 90)
    {
        return $this->resize($srcAbs, $thumbAbs, $width, $height, $quality);
    }

    /**
     * 水印（image / text 两种）。
     *
     * $options:
     *   type     - 'img' | 'text'
     *   value    - 文字水印内容（type=text 时）
     *   color    - 文字颜色 hex（type=text）
     *   font_size- 字号
     *   font_file- 字体文件绝对路径（缺省 STORAGE_PATH . watermark/alibaba.ttf）
     *   image    - 水印图片绝对路径（缺省 STORAGE_PATH . watermark/watermark.png）
     *
     * @param string $srcAbs
     * @param string $dstAbs
     * @param array $options
     * @param int $quality
     * @return bool
     */
    public function watermark($srcAbs, $dstAbs, array $options, $quality = 90)
    {
        $srcInfo = @getimagesize($srcAbs);
        if (!is_array($srcInfo)) {
            return false;
        }
        $srcImg = $this->loadImage($srcAbs, $srcInfo[2]);
        if (!$srcImg) {
            return false;
        }
        $width = $srcInfo[0];
        $height = $srcInfo[1];

        $type = isset($options['type']) ? $options['type'] : 'text';
        $fontFile = isset($options['font_file']) && $options['font_file'] !== ''
            ? $options['font_file']
            : STORAGE_PATH . 'watermark/alibaba.ttf';

        $dstImg = @imagecreatetruecolor($width, $height);
        imagecopy($dstImg, $srcImg, 0, 0, 0, 0, $width, $height);

        if ($type === 'img') {
            $watermarkPath = isset($options['image']) && $options['image'] !== ''
                ? $options['image']
                : STORAGE_PATH . 'watermark/watermark.png';
            if (!file_exists($watermarkPath)) {
                if (PHP_VERSION_ID < 80000) {
                    imagedestroy($dstImg);
                    imagedestroy($srcImg);
                }

                return false;
            }
            $wmInfo = @getimagesize($watermarkPath);
            if (!is_array($wmInfo) || $width < $wmInfo[0] || $height < $wmInfo[1]) {
                if (PHP_VERSION_ID < 80000) {
                    imagedestroy($dstImg);
                    imagedestroy($srcImg);
                }

                return false;
            }
            $wmImg = $this->loadImage($watermarkPath, $wmInfo[2]);
            if (!$wmImg) {
                if (PHP_VERSION_ID < 80000) {
                    imagedestroy($dstImg);
                    imagedestroy($srcImg);
                }

                return false;
            }
            $x = $width - $wmInfo[0] - 10;
            $y = $height - $wmInfo[1] - 10;
            imagecopy($dstImg, $wmImg, $x, $y, 0, 0, $wmInfo[0], $wmInfo[1]);
            if (PHP_VERSION_ID < 80000) {
                imagedestroy($wmImg);
            }
        } else {
            $text = isset($options['value']) ? (string) $options['value'] : '';
            $fontSize = isset($options['font_size']) ? (int) $options['font_size'] : 12;
            $box = @imagettfbbox($fontSize, 0, $fontFile, $text);
            if (!is_array($box)) {
                if (PHP_VERSION_ID < 80000) {
                    imagedestroy($dstImg);
                    imagedestroy($srcImg);
                }

                return false;
            }
            $logoWidth = max($box[2], $box[4]) - min($box[0], $box[6]);
            $rgb = $this->hex2rgb(isset($options['color']) ? $options['color'] : '#000000');
            $color = imagecolorallocate($dstImg, $rgb[0], $rgb[1], $rgb[2]);
            $x = $width - $logoWidth - 10;
            $y = $height - 10;
            imagettftext($dstImg, $fontSize, 0, $x + 1, $y + 1, 5592405, $fontFile, $text);
            imagettftext($dstImg, $fontSize, 0, $x, $y, $color, $fontFile, $text);
        }

        $this->ensureDir(dirname($dstAbs));
        $ok = $this->writeImage($dstImg, $dstAbs, $srcInfo[2], $quality);

        if (PHP_VERSION_ID < 80000) {
            imagedestroy($dstImg);
            imagedestroy($srcImg);
        }

        return $ok;
    }

    /**
     * 把 hex 颜色串转 RGB 数组。
     *
     * @param string $hex
     * @return array{0:int,1:int,2:int}
     */
    public function hex2rgb($hex)
    {
        $hex = (string) $hex;
        if ($hex === '') {
            return array(0, 0, 0);
        }
        if ($hex[0] === '#') {
            $hex = substr($hex, 1);
        }
        if (strlen($hex) === 6) {
            $parts = array($hex[0] . $hex[1], $hex[2] . $hex[3], $hex[4] . $hex[5]);
        } elseif (strlen($hex) === 3) {
            $parts = array($hex[0] . $hex[0], $hex[1] . $hex[1], $hex[2] . $hex[2]);
        } else {
            return array(0, 0, 0);
        }

        return array_map('hexdec', $parts);
    }

    /**
     * 按类型读取 GD 图像。
     *
     * @param string $abs
     * @param int $type
     * @return resource|\GdImage|false
     */
    private function loadImage($abs, $type)
    {
        switch ($type) {
            case 1:
                return @imagecreatefromgif($abs);
            case 2:
                return @imagecreatefromjpeg($abs);
            case 3:
                return @imagecreatefrompng($abs);
            case 18:
                return @imagecreatefromwebp($abs);
            default:
                return false;
        }
    }

    /**
     * 按类型新建画布（透明保留 png/webp）。
     *
     * @param int $width
     * @param int $height
     * @param int $srcType
     * @return resource|\GdImage|false
     */
    private function createCanvas($width, $height, $srcType)
    {
        if (function_exists('imagecreatetruecolor')) {
            $canvas = imagecreatetruecolor($width, $height);
            if ($srcType === 3 || $srcType === 18) {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
            }

            return $canvas;
        }

        return imagecreate($width, $height);
    }

    /**
     * 按类型写出图像。
     *
     * @param resource|\GdImage $image
     * @param string $abs
     * @param int $type
     * @param int $quality
     * @return bool
     */
    private function writeImage($image, $abs, $type, $quality)
    {
        switch ($type) {
            case 1:
                return @imagegif($image, $abs);
            case 2:
                return @imagejpeg($image, $abs, $quality);
            case 3:
                return @imagepng($image, $abs);
            case 18:
                return @imagewebp($image, $abs, $quality);
            default:
                return false;
        }
    }

    /**
     * 按目标路径扩展名重新编码（jpg/png/webp），可选限宽。
     *
     * @param string $srcAbs
     * @param string $dstAbs
     * @param int $width 0 表示不限宽
     * @param int $height 0 表示不限高
     * @param int $quality
     * @return bool
     */
    public function transcode($srcAbs, $dstAbs, $width = 0, $height = 0, $quality = 90)
    {
        $info = $this->info($srcAbs);
        if (!$info) {
            return false;
        }
        $srcImage = $this->loadImage($srcAbs, $info['type']);
        if (!$srcImage) {
            return false;
        }

        $dstType = $this->imagetypeFromExt(pathinfo($dstAbs, PATHINFO_EXTENSION));
        if ($dstType <= 0) {
            $dstType = $info['type'];
        }

        $outW = (int) $info['width'];
        $outH = (int) $info['height'];
        $needResize = false;
        if ($width > 0 && $info['width'] > $width) {
            $needResize = true;
            $outW = (int) round($width);
            $outH = (int) round($info['height'] * $outW / $info['width']);
        } elseif ($height > 0 && $info['height'] > $height) {
            $needResize = true;
            $outH = (int) round($height);
            $outW = (int) round($info['width'] * $outH / $info['height']);
        }

        $outImage = $srcImage;
        if ($needResize) {
            if ($outW <= 0 || $outH <= 0) {
                if (PHP_VERSION_ID < 80000) {
                    imagedestroy($srcImage);
                }

                return false;
            }
            $outImage = $this->createCanvas($outW, $outH, $dstType);
            if (function_exists('imagecopyresampled')) {
                imagecopyresampled($outImage, $srcImage, 0, 0, 0, 0, $outW, $outH, $info['width'], $info['height']);
            } else {
                imagecopyresized($outImage, $srcImage, 0, 0, 0, 0, $outW, $outH, $info['width'], $info['height']);
            }
            if (PHP_VERSION_ID < 80000) {
                imagedestroy($srcImage);
            }
        }

        $this->ensureDir(dirname($dstAbs));
        if (file_exists($dstAbs)) {
            @unlink($dstAbs);
        }
        $ok = $this->writeImage($outImage, $dstAbs, $dstType, $quality);
        if (PHP_VERSION_ID < 80000) {
            imagedestroy($outImage);
        }

        return $ok;
    }

    /**
     * 扩展名 → getimagesize 类型常量。
     *
     * @param string $ext
     * @return int
     */
    private function imagetypeFromExt($ext)
    {
        $ext = strtolower((string) $ext);
        if ($ext === 'jpg' || $ext === 'jpeg') {
            return 2;
        }
        if ($ext === 'png') {
            return 3;
        }
        if ($ext === 'gif') {
            return 1;
        }
        if ($ext === 'webp') {
            return 18;
        }

        return 0;
    }

    /**
     * 确保目录存在。
     *
     * @param string $dir
     * @return bool
     */
    private function ensureDir($dir)
    {
        if (is_dir($dir)) {
            return true;
        }

        return @mkdir($dir, 0777, true) || is_dir($dir);
    }
}
