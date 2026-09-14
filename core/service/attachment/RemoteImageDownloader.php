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
     * @param Disk $disk
     * @param string $remoteUrl
     * @param string $folder disk root 内子目录
     * @param string $customFilename 自定义文件名（含扩展），空时按 `{YmdHis}_{rand6}.{ext}` 随机
     * @param AttachmentUploadOptions $options
     * @return array{relative:string,absolute:string,basename:string}|null 失败 null
     */
    public function fetch(Disk $disk, $remoteUrl, $folder, $customFilename, AttachmentUploadOptions $options)
    {
        if (!preg_match('/^https?:\/\/([^\s\/$.?#].[^\s]*)$/iu', $remoteUrl)) {
            return null;
        }
        if (!self::isPublicUrl($remoteUrl)) {
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
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            return false;
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        if (isset($parts['port']) && !in_array((int) $parts['port'], array(80, 443), true)) {
            return false;
        }

        $host = trim($parts['host'], '[]');
        $addresses = self::resolveHost($host);
        if (empty($addresses)) {
            return false;
        }

        foreach ($addresses as $ip) {
            if (!self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 解析主机名到 IP 列表；主机名本身即 IP 时直接返回。
     *
     * @param string $host
     * @return array
     */
    private static function resolveHost($host)
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return array($host);
        }

        $addresses = array();
        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            $addresses = $v4;
        }
        if (defined('DNS_AAAA')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ipv6']) && $record['ipv6'] !== '') {
                        $addresses[] = $record['ipv6'];
                    }
                }
            }
        }

        return $addresses;
    }

    /**
     * 判断 IP 是否为公网地址（排除私有段、环回、链路本地与保留段）。
     *
     * @param string $ip
     * @return bool
     */
    private static function isPublicIp($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // FILTER_FLAG_NO_PRIV_RANGE/NO_RES_RANGE 覆盖 10/8、172.16/12、192.168/16、127/8、
        // 169.254/16、::1、fc00::/7 等；返回 false 即命中私有或保留段。
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
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
