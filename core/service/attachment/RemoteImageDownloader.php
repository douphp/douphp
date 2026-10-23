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
use Dou\Core\Infra\Security\UrlGuard;
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
    /** @var int 单跳整体超时（秒） */
    const REQUEST_TIMEOUT = 10;

    /** @var int 单跳连接超时（秒） */
    const CONNECT_TIMEOUT = 5;

    /** @var int 最大重定向跳数（每跳单独校验目标地址） */
    const MAX_REDIRECTS = 3;

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
     * 落盘扩展名一律由内容嗅探决定（getimagesize），且必须命中磁盘扩展白名单；
     * URL 上的扩展名不可信，非图片内容一律拒绝——防止 x.php 之类的脚本被写进站点目录。
     * 先写 `.part` 临时文件，校验通过后才 rename 为最终名，失败路径不留任何产物。
     *
     * @param Disk $disk
     * @param string $remoteUrl
     * @param string $folder disk root 内子目录
     * @param string $customFilename 自定义主名（扩展名忽略，由嗅探结果决定），空时按 `{YmdHis}_{rand6}.{ext}` 随机
     * @param AttachmentUploadOptions $options
     * @return array{relative:string,absolute:string,basename:string,original_name:string,mime_type:string}|null 失败 null
     */
    public function fetch(Disk $disk, $remoteUrl, $folder, $customFilename, AttachmentUploadOptions $options)
    {
        if (!preg_match('/^https?:\/\/([^\s\/$.?#].[^\s]*)$/iu', $remoteUrl)) {
            return null;
        }
        if (!self::isPublicUrl($remoteUrl)) {
            return null;
        }

        // 远程名仅作 dou_file.original_name 元数据；落盘扩展名一律由内容嗅探决定，
        // 不再从 URL basename / wx_fmt 参数推断（该支路曾把 x.php 原样落盘，可被 web 解析执行）。
        // 入库前清洗：剔除控制字符；非法 UTF-8（伪造 URL 字节）整体置空；超长按字节截断后
        // 回退到合法 UTF-8 边界，避免写入 utf8mb4 列时报 Incorrect string value。
        $remoteFilename = (string) preg_replace('/[\x00-\x1f\x7f]/', '', rawurldecode(pathinfo(parse_url($remoteUrl, PHP_URL_PATH), PATHINFO_BASENAME)));
        if (preg_match('//u', $remoteFilename) !== 1) {
            $remoteFilename = '';
        } elseif (strlen($remoteFilename) > 180) {
            $remoteFilename = substr($remoteFilename, 0, 180);
            while ($remoteFilename !== '' && preg_match('//u', $remoteFilename) !== 1) {
                $remoteFilename = substr($remoteFilename, 0, -1);
            }
        }

        $maxKb = AttachmentPolicy::maxKb($disk, $options);
        $maxBytes = $maxKb * 1024;

        // 重定向不交给 cURL 自动跟随：每一跳都要重新做公网地址校验，
        // 否则可用 302 把已通过校验的公网 URL 折回内网地址。
        $currentUrl = $remoteUrl;
        $data = false;
        $status = 0;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $ch = curl_init($currentUrl);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_USERAGENT => 'Mozilla/5.0 Image Downloader',
                CURLOPT_HEADER => false,
                CURLOPT_MAXFILESIZE => $maxBytes,
            ));
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $error = curl_error($ch);
            if (PHP_VERSION_ID < 80000) {
                curl_close($ch);
            }

            if ($body === false) {
                error_log('Download failed: ' . $error);
                return null;
            }

            if ($status >= 300 && $status < 400 && $location !== '') {
                if (!preg_match('/^https?:\/\//i', $location) || !self::isPublicUrl($location)) {
                    error_log('Download failed: redirect target rejected');
                    return null;
                }
                $currentUrl = $location;
                continue;
            }

            $data = $body;
            break;
        }

        if ($data === false || $status !== 200) {
            error_log('Download failed: unexpected status ' . $status);

            return null;
        }
        if (strlen($data) > $maxBytes) {
            error_log('Download failed: response exceeds ' . $maxKb . 'KB');

            return null;
        }

        // 先落 `.part` 临时文件，嗅探真实类型并命中白名单后才 rename 为最终名；
        // 任何一步失败都删除临时文件，不把未校验内容留在磁盘上。
        $folderRel = $folder !== '' ? trim($folder, '/') . '/' : '';
        $base = $this->resolveBaseName($customFilename);
        $partAbsolute = $disk->path($folderRel . $base . '.part');
        $targetDir = dirname($partAbsolute);
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }
        if (file_put_contents($partAbsolute, $data) === false) {
            @unlink($partAbsolute);

            return null;
        }

        $extension = AttachmentPolicy::imageExtensionFromPath($partAbsolute);
        if ($extension === '' || !AttachmentPolicy::extensionAllowed($extension, AttachmentPolicy::allowExtensions($disk, $options))) {
            error_log('Download failed: rejected non-image content (detected: ' . ($extension !== '' ? $extension : 'unknown') . ')');
            @unlink($partAbsolute);

            return null;
        }

        $finalName = $base . '.' . $extension;
        $relativePath = $folderRel . $finalName;
        $absolutePath = $disk->path($relativePath);
        if (!@rename($partAbsolute, $absolutePath)) {
            @unlink($partAbsolute);

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
            'original_name' => $remoteFilename,
            'mime_type' => AttachmentPolicy::mimeTypeOf($extension),
        );
    }

    /**
     * 解析落盘主名（不含扩展名）：customFilename 先取 basename 再剔除路径 / 控制字符 /
     * URL 敏感字符，清洗后为空（未提供 / 只剩危险字符）时回退 `{YmdHis}_{rand6}` 随机名。
     *
     * 冒号会触发 PathNormalizer 的 ADS 校验异常，`? # % &` 空格等会破坏拼接后的 URL
     * （urlFromSiteRoot 不做编码），`< > | * " '` 跨平台落盘不安全，一并剔除。
     *
     * @param string $customFilename
     * @return string
     */
    private function resolveBaseName($customFilename)
    {
        $base = '';
        if ((string) $customFilename !== '') {
            $base = (string) pathinfo(basename((string) $customFilename), PATHINFO_FILENAME);
            $base = str_replace(array("\0", '/', '\\'), '', $base);
            $base = (string) preg_replace('/[\x00-\x1f\x7f]+/', '', $base);
            $base = str_replace(array(':', '?', '#', '%', '&', '<', '>', '|', '*', '"', "'", ' '), '', $base);
            $base = trim($base, '. ');
        }
        if ($base === '') {
            $base = date('YmdHis') . '_' . Str::randomByType('letter', 6);
        }

        return $base;
    }

    /**
     * 判断 URL 的目标主机是否解析到公网地址。
     *
     * 仅放行 http/https + 标准端口，并要求主机名解析出的每个 A/AAAA 记录都落在公网段，
     * 拦住指向内网服务与云元数据端点（169.254.169.254）的抓取请求。
     *
     * @param string $url
     * @return bool
     */
    private static function isPublicUrl($url)
    {
        return UrlGuard::isPublicUrl($url);
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
