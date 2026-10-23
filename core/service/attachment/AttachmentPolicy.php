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
 * Release Date: 2026-10-21
 */

namespace Dou\Core\Service\Attachment;

use Dou\Core\Filesystem\Disk;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 附件策略：扩展名白名单、体积上限与图片真实类型嗅探的单一权威源。
 *
 * 上传支路（{@see AttachmentService}）与远程图转存支路（{@see RemoteImageDownloader}）
 * 共用本类，确保两侧白名单 / 上限 / 类型映射不再分叉。
 */
class AttachmentPolicy
{
    /** @var string 默认图片扩展名白名单 */
    const DEFAULT_IMAGE_EXTENSIONS = 'jpg,jpeg,gif,png,webp,ico';

    /** @var int 默认上传 / 转存体积上限（KB） */
    const DEFAULT_MAX_KB = 2048;

    /**
     * 解析允许扩展名（options → disk 配置 → 默认）。
     *
     * @param Disk $disk
     * @param AttachmentUploadOptions $options
     * @return string 逗号分隔的允许扩展名清单
     */
    public static function allowExtensions(Disk $disk, AttachmentUploadOptions $options)
    {
        if ($options->getAllowExtensions() !== '') {
            return $options->getAllowExtensions();
        }
        $cfg = (string) $disk->getConfig('allow_extensions', '');

        // 默认白名单不含 svg：SVG 可内嵌 <script>/onload，作为图片直链访问会触发存储型 XSS。
        // 确需 svg 的磁盘可在 filesystems.disks.{name}.allow_extensions 显式开启。
        return $cfg !== '' ? $cfg : self::DEFAULT_IMAGE_EXTENSIONS;
    }

    /**
     * 解析体积上限 KB（options → disk 配置 → 默认 2048）。
     *
     * @param Disk $disk
     * @param AttachmentUploadOptions $options
     * @return int
     */
    public static function maxKb(Disk $disk, AttachmentUploadOptions $options)
    {
        if ($options->getUploadMaxKb() > 0) {
            return (int) $options->getUploadMaxKb();
        }
        $cfg = (int) $disk->getConfig('upload_max_kb', 0);

        return $cfg > 0 ? $cfg : self::DEFAULT_MAX_KB;
    }

    /**
     * 校验扩展名是否在允许清单中。
     *
     * @param string $extension
     * @param string $allow
     * @return bool
     */
    public static function extensionAllowed($extension, $allow)
    {
        $extension = strtolower((string) $extension);
        if ($extension === '') {
            return false;
        }
        $allowList = array_map('trim', explode(',', strtolower((string) $allow)));

        return in_array($extension, $allowList, true);
    }

    /**
     * 嗅探文件真实图片类型并给出落盘扩展名（不信任文件名 / URL 上的扩展名）。
     *
     * 映射基于 getimagesize 的 IMAGETYPE_* 数字常量：1 gif / 2 jpeg / 3 png /
     * 17 ico / 18 webp（webp 常量 PHP 7.1+ 才有；PHP 5.6 下 getimagesize 不识别
     * webp 会返回 false，此时按「非图片」拒绝，属预期降级）。
     *
     * @param string $absolutePath
     * @return string 命中返回扩展名（jpeg 归一为 jpg），不识别返回 ''
     */
    public static function imageExtensionFromPath($absolutePath)
    {
        $info = @getimagesize($absolutePath);
        if (!is_array($info) || !isset($info[2])) {
            return '';
        }

        switch ((int) $info[2]) {
            case 1:
                return 'gif';
            case 2:
                return 'jpg';
            case 3:
                return 'png';
            case 17:
                return 'ico';
            case 18:
                return 'webp';
            default:
                return '';
        }
    }

    /**
     * 扩展名 → MIME（dou_file.mime_type 元数据用）。
     *
     * @param string $extension
     * @return string
     */
    public static function mimeTypeOf($extension)
    {
        $map = array(
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
        );
        $ext = strtolower((string) $extension);

        return isset($map[$ext]) ? $map[$ext] : '';
    }
}