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

namespace Dou\Core\Service\Attachment;

use Dou\Core\Filesystem\Disk;
use Dou\Core\Infra\Image\ImageManager;
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 单张远程图片下载：cURL + 落盘部分。
 *
 * 不感知 dou_file 表：负责拉取 + 落盘 + 可选缩放/水印；返回相对站点根的最终路径。
 * 调用方根据需要再调 AttachmentRepository::insert 写入元数据并返回 URL/number。
 */
class RemoteImageDownloader
{
    /** @var ImageManager */
    private $images;

    /**
     * @param ImageManager $images
     */
    public function __construct(ImageManager $images)
    {
        $this->images = $images;
    }

    /**
     * 下载远程图到指定磁盘 / 子目录。
     *
     * @param Disk $disk
     * @param string $remoteUrl
     * @param string $folder disk root 内子目录
     * @param string $customFilename 自定义文件名（含扩展），空时按 `{YmdHis}_{rand6}.{ext}` 随机
     * @param AttachmentUploadOptions $options
     * @return array{relative:string,absolute:string,basename:string}|null 失败 null
     */
    public function fetch(Disk $disk, $remoteUrl, $folder, $customFilename, AttachmentUploadOptions $options)
    {
        if (!preg_match('/^(https?|ftp):\/\/([^\s\/$.?#].[^\s]*)$/iu', $remoteUrl)) {
            return null;
        }

        $remoteFilename = rawurldecode(pathinfo(parse_url($remoteUrl, PHP_URL_PATH), PATHINFO_BASENAME));
        if (empty($remoteFilename) || !pathinfo($remoteFilename, PATHINFO_EXTENSION)) {
            $extension = 'jpg';
            if (preg_match('/[&?]wx_fmt=(\w+)/i', $remoteUrl, $m)) {
                $extension = $m[1];
            }
        } else {
            $extension = strtolower(pathinfo($remoteFilename, PATHINFO_EXTENSION));
        }

        $randName = date('YmdHis') . '_' . Str::randomByType('letter', 6) . '.' . $extension;
        $finalName = $customFilename !== '' ? $customFilename : $randName;

        $ch = curl_init($remoteUrl);
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'Mozilla/5.0 Image Downloader',
            CURLOPT_HEADER => false,
        );
        if (ini_get('open_basedir') == '' && !ini_get('safe_mode')) {
            $opts[CURLOPT_FOLLOWLOCATION] = true;
        } else {
            $opts[CURLOPT_FOLLOWLOCATION] = false;
        }
        curl_setopt_array($ch, $opts);
        $data = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($data === false || $status !== 200) {
            error_log('Download failed: ' . curl_error($ch));
            if (PHP_VERSION_ID < 80000) {
                curl_close($ch);
            }

            return null;
        }
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        $folderRel = $folder !== '' ? trim($folder, '/') . '/' : '';
        $relativePath = $folderRel . $finalName;
        $absolutePath = $disk->path($relativePath);
        $targetDir = dirname($absolutePath);
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }
        if (file_put_contents($absolutePath, $data) === false) {
            return null;
        }

        $quality = $this->resolveQuality($disk, $options);
        $imgWidth = $options->getImageWidth();
        if ($imgWidth > 0) {
            $this->images->resize($absolutePath, $absolutePath, $imgWidth, 0, $quality);
        }
        $wm = $options->getWatermark();
        if ($wm !== '') {
            $opts = $this->buildWatermarkOptions($wm);
            $this->images->watermark($absolutePath, $absolutePath, $opts, $quality);
        }

        return array(
            'relative' => $relativePath,
            'absolute' => $absolutePath,
            'basename' => $finalName,
        );
    }

    /**
     * 解析图片质量：选项优先，磁盘配置兜底，最后 90。
     *
     * @param Disk $disk
     * @param AttachmentUploadOptions $options
     * @return int
     */
    private function resolveQuality(Disk $disk, AttachmentUploadOptions $options)
    {
        $q = $options->getImageQuality();
        if ($q > 0) {
            return $q;
        }
        $cfg = $disk->getConfig('image_quality', 0);
        if ((int) $cfg > 0) {
            return (int) $cfg;
        }

        return 90;
    }

    /**
     * 构造水印选项：存在 storage/watermark/watermark.png 走 img，否则走 text。
     *
     * @param string $watermark
     * @return array
     */
    private function buildWatermarkOptions($watermark)
    {
        if (file_exists(STORAGE_PATH . 'watermark/watermark.png')) {
            return array('type' => 'img');
        }

        return array(
            'type' => 'text',
            'value' => $watermark,
            'color' => '#FFFFFF',
            'font_size' => 12,
        );
    }
}
