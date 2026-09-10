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
use Dou\Core\Foundation\Configuration\Config;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 富文本编辑器内远程图导入。
 *
 * 主流程：扫描 `<img src>` / vditor `![](url)` → 排除已在本磁盘的 → 调
 * {@see RemoteImageDownloader} 拉取 → 串成本地 URL 替换。
 */
class ContentImageImporter
{
    /** @var RemoteImageDownloader */
    private $downloader;

    /** @var AttachmentRepository */
    private $repository;

    /**
     * @param RemoteImageDownloader $downloader
     * @param AttachmentRepository $repository
     */
    public function __construct(RemoteImageDownloader $downloader, AttachmentRepository $repository)
    {
        $this->downloader = $downloader;
        $this->repository = $repository;
    }

    /**
     * 处理一段富文本内容里的远程图片，落地为本地资源并替换链接。
     *
     * @param Disk $disk
     * @param mixed $module
     * @param mixed $itemId 业务主键（draft 场景固定传 0，由 draftMeta 承载身份与 token）
     * @param string $type
     * @param string $content
     * @param string $folder
     * @param AttachmentUploadOptions $options
     * @param array $draftMeta 非空时切换到 draft 写入路径，键：
     *                         uploader_type / uploader_id / draft_token / draft_expire_at
     * @param array $ownedMeta owned 写入路径的 uploader 身份，键：uploader_type / uploader_id
     * @return string
     */
    public function importContent(Disk $disk, $module, $itemId, $type, $content, $folder, AttachmentUploadOptions $options, array $draftMeta = array(), array $ownedMeta = array())
    {
        $pattern = (Config::get('site.editor', '') === 'vditor')
            ? '/\]\((https?:\/\/[^)\s]+)/i'
            : '/<img\b[^>]*\ssrc=["\']([^"\']+)["\'][^>]*>/i';

        $self = $this;
        $publicBase = rtrim($disk->url(''), '/');

        return preg_replace_callback(
            $pattern,
            function ($matches) use ($self, $disk, $module, $itemId, $type, $folder, $options, $publicBase, $draftMeta, $ownedMeta) {
                $remoteUrl = $matches[1];
                if (strpos($remoteUrl, $publicBase) === 0) {
                    return $matches[0];
                }
                $localUrl = $self->downloadOne($disk, $remoteUrl, $module, $itemId, $type, $folder, '', $options, 'path', $draftMeta, $ownedMeta);
                if (!$localUrl) {
                    return $matches[0];
                }

                return str_replace($remoteUrl, $localUrl, $matches[0]);
            },
            $content
        );
    }

    /**
     * 单张拉取：写 dou_file 记录后返回本地 URL 或 number。
     *
     * @param Disk $disk
     * @param string $remoteUrl
     * @param mixed $module
     * @param mixed $itemId
     * @param string $type
     * @param string $folder
     * @param string $customFilename
     * @param AttachmentUploadOptions $options
     * @param string $outputFormat 'path' | 'number'
     * @param array $draftMeta 非空时按 draft 写入：item_id 强制 0、状态 draft、带 uploader / token / expire
     * @param array $ownedMeta owned 写入路径的 uploader 身份，键：uploader_type / uploader_id
     * @return string|null
     */
    public function downloadOne(Disk $disk, $remoteUrl, $module, $itemId, $type, $folder, $customFilename, AttachmentUploadOptions $options, $outputFormat = 'path', array $draftMeta = array(), array $ownedMeta = array())
    {
        $result = $this->downloader->fetch($disk, $remoteUrl, $folder, $customFilename, $options);
        if ($result === null) {
            return null;
        }

        $diskRoot = $disk->getConfig('root', '');
        $relativeToSite = $diskRoot . ltrim($result['relative'], '/');
        clearstatcache();
        $size = file_exists($result['absolute']) ? filesize($result['absolute']) : 0;
        $now = date('Y-m-d H:i:s');

        $number = $this->repository->allocateNumber();
        $row = array(
            'number' => $number,
            'file' => $relativeToSite,
            'module' => $module,
            'item_id' => $itemId,
            'type' => $type,
            'size' => $size,
            'thumb_size' => 0,
            'last_used_at' => $now,
            'created_at' => $now,
        );
        if (!empty($draftMeta)) {
            $row['item_id'] = 0;
            $row['status'] = 'draft';
            $row['draft_token'] = isset($draftMeta['draft_token']) ? $draftMeta['draft_token'] : '';
            $row['draft_expire_at'] = !empty($draftMeta['draft_expire_at'])
                ? date('Y-m-d H:i:s', (int) $draftMeta['draft_expire_at'])
                : null;
            if (!empty($draftMeta['uploader_type']) && isset($draftMeta['uploader_id'])) {
                $row['uploader_type'] = (string) $draftMeta['uploader_type'];
                $row['uploader_id'] = (int) $draftMeta['uploader_id'];
            }
        } elseif (!empty($ownedMeta['uploader_type'])) {
            $row['status'] = 'owned';
            $row['uploader_type'] = (string) $ownedMeta['uploader_type'];
            $row['uploader_id'] = isset($ownedMeta['uploader_id']) ? (int) $ownedMeta['uploader_id'] : 0;
        }
        $this->repository->insert($row);

        if ($outputFormat === 'number') {
            return $number;
        }

        return $disk->url($result['relative']);
    }
}
