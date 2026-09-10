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

namespace Dou\Core\Facade;

use Dou\Core\Foundation\Facade\StaticFacade;
use Dou\Core\Service\Attachment\AttachmentService;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * Attachment 静态门面：底层为 {@see AttachmentService} 容器单例。
 *
 * 与 helper `attachment()` 等价。
 *
 * @method static string|false store(string $module, $itemId, UploadedFile $file, string $type = 'main', AttachmentUploadOptions $options = null)
 * @method static string|false storeToDirectory(UploadedFile $file, string $disk, string $directory = '', string $basename = '', string $type = 'main', AttachmentUploadOptions $options = null)
 * @method static array chunkedStore(string $module, $itemId, string $fileField = 'file', string $type = 'main', string $customFilename = '', string $allowFileType = 'zip,rar', $diskName = null)
 * @method static string storeContentImages(string $module, $itemId, string $content, string $type = 'content', string $folder = '', AttachmentUploadOptions $options = null)
 * @method static string|false storeFromUrl(string $module, $itemId, string $remoteUrl, string $type = 'content', string $folder = '', string $customFilename = '', AttachmentUploadOptions $options = null, string $outputFormat = 'path')
 * @method static bool delete(string $number)
 * @method static bool renameStoredFile(string $number, string $newBasename)
 * @method static bool moveStoredFileToDirectory(string $number, string $newDirRelative)
 * @method static string url(string $number, bool $thumb = false)
 * @method static array urlBatch($numbers, bool $thumb = false)
 * @method static string cacheTag($fileUpdateTime = '')
 * @method static array|string gallery(string $module, $itemId, string $type, bool $arrayMode = false)
 * @method static string galleryFirst(string $module, $itemId)
 * @method static array galleryFirstMap(string $module, array $ids)
 * @method static mixed repository()
 */
class Attachment extends StaticFacade
{
    /**
     * 容器中以 AttachmentService FQCN 为 key 注册。
     *
     * @return string
     */
    protected static function getAccessor()
    {
        return AttachmentService::class;
    }
}
