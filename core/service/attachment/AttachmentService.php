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

use Dou\Core\Facade\DB;
use Dou\Core\Filesystem\Disk;
use Dou\Core\Filesystem\FilesystemManager;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Infra\Image\ImageManager;
use Dou\Core\Support\Check;
use Dou\Core\Support\FileHelper;
use Dou\Core\Support\Str;
use Dou\Core\Web\Http\UploadedFile;

use function md5;
use function microtime;
use function uniqid;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 附件领域服务：业务侧通过 attachment() helper 拿到本类实例。
 *
 * 与 Storage（纯文件读写）的关系：
 *   - Storage 不感知 dou_file 表；只做 disk + path + URL + 元信息。
 *   - AttachmentService 在 Storage 之上处理「附件元数据」业务：number 分配 / 上传写库 /
 *     富文本批量拉图 / 分块上传 / 画廊 / `.file` 号 URL 解析等。
 *
 * 公开方法分组：
 *   - 上传（owned）：store / storeToDirectory / chunkedStore
 *   - 上传（draft）：storeDraft / storeDraftContentImages
 *   - 内容拉图：storeContentImages / storeFromUrl
 *   - 草稿认领：claimByToken / cleanupUserDrafts / newDraftToken
 *   - 元数据：delete / renameStoredFile / moveStoredFileToDirectory
 *   - URL：url / urlBatch / cacheTag
 *   - 画廊：gallery / galleryFirst / galleryFirstMap
 *
 * 身份模型：
 *   - $identityKind ∈ {'admin', 'user', 'work'}，分别对应 dou_admin / dou_user / dou_work；
 *   - $identityId   为对应身份表主键 id（int）。
 *   - admin / front / api 三 guard 中：admin guard → 'admin'；front guard → 'user'；
 *     api guard 上传时由调用方决定走 'user'（前台会员）或 'work'（小程序工作号）。
 */
class AttachmentService
{
    /** draft 默认存活时长（秒）：7 天后视为过期，由懒 GC 清理。 */
    const DRAFT_LIFETIME_SECONDS = 604800;

    /** @var FilesystemManager */
    private $storage;

    /** @var ImageManager */
    private $images;

    /** @var AttachmentRepository */
    private $repository;

    /** @var UrlResolver */
    private $urls;

    /** @var GalleryQuery */
    private $gallery;

    /** @var ContentImageImporter */
    private $contentImporter;

    /** @var ChunkedUploadHandler */
    private $chunkedHandler;

    /**
     * @param FilesystemManager $storage
     * @param ImageManager $images
     */
    public function __construct(FilesystemManager $storage, ImageManager $images)
    {
        $this->storage = $storage;
        $this->images = $images;
        $this->repository = new AttachmentRepository();
        $this->urls = new UrlResolver();
        $this->gallery = new GalleryQuery();
        $downloader = new RemoteImageDownloader($images);
        $this->contentImporter = new ContentImageImporter($downloader, $this->repository);
        $this->chunkedHandler = new ChunkedUploadHandler($this->repository);
    }

    // -----------------------------------------------------------------
    // 上传
    // -----------------------------------------------------------------

    /**
     * 单文件上传并写 dou_file 表。
     *
     * 流程：
     *   ① 解析 disk（options.disk → module 名 → 异常）
     *   ② 若业务表中已有 number 且对应 dou_file 行存在 → 续传到原目录、走 update 分支
     *      否则分配新 number → 写到 disk root + options.directory，走 insert 分支
     *   ③ 落盘 + 校验后置（运行期 KB / 扩展名校验）
     *   ④ 可选 resize / watermark / thumb
     *   ⑤ 写 dou_file 行（insert/update）
     *
     * @param mixed $module
     * @param mixed $itemId
     * @param UploadedFile|null $file 未选文件时调用方可直接传 {@see UploadedFile::fromGlobals} 的 null 结果
     * @param string $type
     * @param AttachmentUploadOptions|null $options
     * @return string 分配/复用的 file number；上传缺省 / 失败时返回 ''
     */
    public function store($module, $itemId, $file = null, $type = 'main', $options = null)
    {
        $options = ($options instanceof AttachmentUploadOptions) ? $options : AttachmentUploadOptions::create();
        if (!($file instanceof UploadedFile) || !$file->isValid()) {
            return '';
        }

        $uploaderType = $this->requireUploaderType($options);
        $uploaderId = (int) $options->getUploaderId();

        $disk = $this->resolveDisk($module, $options);
        $allow = $this->resolveAllowExtensions($disk, $options);
        $maxKb = $this->resolveUploadMaxKb($disk, $options);
        $quality = $this->resolveImageQuality($disk, $options);
        $thumbSub = (string) $disk->getConfig('thumb_directory', '');

        $primaryKey = $options->getPrimaryKey();
        $businessField = $this->resolveBusinessField($type, $options);
        $existingNumber = '';
        if ($businessField !== '' && DB::fieldExist($module, $businessField)) {
            $existingNumber = DB::table($module)
                ->where($primaryKey, $itemId)
                ->value($businessField);
        }
        $existingNumber = Check::fileNumber($existingNumber) ? $existingNumber : '';

        $existingRow = null;
        if ($existingNumber !== '') {
            $existingRow = $this->repository->findByNumber($existingNumber);
        }
        $action = $existingRow ? 'update' : 'insert';
        $number = $existingRow ? $existingNumber : $this->repository->allocateNumber();

        $directoryRelative = '';
        if ($existingRow) {
            $oldFilePath = isset($existingRow['file']) ? (string) $existingRow['file'] : '';
            $oldDir = dirname($oldFilePath);
            $diskRoot = (string) $disk->getConfig('root', '');
            $diskRootTrim = rtrim($diskRoot, '/');
            if ($oldDir === '.') {
                $directoryRelative = '';
            } elseif ($oldDir === $diskRootTrim || strpos($oldDir . '/', $diskRoot) === 0) {
                $directoryRelative = ltrim(substr($oldDir, strlen($diskRootTrim)), '/');
                if ($directoryRelative !== '') {
                    $directoryRelative .= '/';
                }
            } else {
                $directoryRelative = $options->getDirectory();
            }
        } else {
            $dirOpt = $options->getDirectory();
            $directoryRelative = $dirOpt !== '' ? trim($dirOpt, '/') . '/' : '';
        }

        $basename = $this->resolveBasename($options, $existingRow);
        $extension = $file->getClientOriginalExtension();
        if ($extension === '') {
            $extension = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
        }
        if (!$this->extensionAllowed($extension, $allow)) {
            return $this->fail($type, lang('file_support') . $allow . lang('file_support_no') . $extension);
        }
        if ($file->getSizeKb() > $maxKb) {
            return $this->fail($type, lang('file_out_size') . $maxKb . 'KB');
        }

        $finalFileName = $basename . '.' . $extension;
        $relativePathInDisk = $directoryRelative . $finalFileName;
        $absoluteTarget = $disk->path($relativePathInDisk);
        $absoluteDir = dirname($absoluteTarget);
        if (!is_dir($absoluteDir)) {
            @mkdir($absoluteDir, 0777, true);
        }
        if (FileHelper::permission($absoluteDir . '/') !== 'write') {
            return $this->fail($type, lang('file_dir_wrong'));
        }
        if (file_exists($absoluteTarget)) {
            @unlink($absoluteTarget);
        }
        if (!$file->move($absoluteDir, basename($absoluteTarget))) {
            return $this->fail($type, $this->formatFileWrong());
        }

        $imgWidth = $options->getImageWidth();
        if ($imgWidth > 0) {
            $this->images->resize($absoluteTarget, $absoluteTarget, $imgWidth, 0, $quality);
        }

        $watermark = $options->getWatermark();
        if ($watermark !== '') {
            $opts = $this->buildWatermarkOptions($watermark);
            $this->images->watermark($absoluteTarget, $absoluteTarget, $opts, $quality);
        }

        $thumbW = $options->getThumbWidth();
        $thumbH = $options->getThumbHeight();
        $thumbSize = 0;
        if ($thumbW > 0 || $thumbH > 0) {
            $thumbAbs = $this->buildThumbAbsolute($absoluteTarget, $thumbSub);
            if ($this->images->thumb($absoluteTarget, $thumbAbs, $thumbW, $thumbH, $quality)) {
                clearstatcache();
                $thumbSize = file_exists($thumbAbs) ? (int) filesize($thumbAbs) : 0;
            }
        }

        $diskRoot = (string) $disk->getConfig('root', '');
        $relativeToSite = $diskRoot . $relativePathInDisk;
        clearstatcache();
        $size = file_exists($absoluteTarget) ? (int) filesize($absoluteTarget) : 0;
        $now = date('Y-m-d H:i:s');

        if ($action === 'insert') {
            $this->repository->insert(array(
                'number' => $number,
                'file' => $relativeToSite,
                'module' => $module,
                'item_id' => $itemId,
                'type' => $type,
                'size' => $size,
                'thumb_size' => $thumbSize,
                'last_used_at' => $now,
                'created_at' => $now,
                'uploader_type' => $uploaderType,
                'uploader_id' => $uploaderId,
                'status' => 'owned',
            ));
        } else {
            $this->repository->updateByNumber($number, array(
                'file' => $relativeToSite,
                'size' => $size,
                'thumb_size' => $thumbSize,
                'last_used_at' => $now,
            ));
            $this->repository->touchUpdateTime();
        }

        return $number;
    }

    /**
     * 按附件号替换原图（图片编辑裁剪）：保持原目录、原文件名、原扩展名覆盖落盘，
     * 路径不变 → 业务表引用与前台 URL 零影响；随后按配置限宽 / 打水印，
     * 若该图存在 `*_thumb` 缩略图则按后台缩略图尺寸重建，最后更新 dou_file 体积字段。
     *
     * @param string $number dou_file 附件号
     * @param UploadedFile|null $file 裁剪产物（扩展名须与原图一致）
     * @param AttachmentUploadOptions|null $options 限宽 / 水印等后处理选项
     * @return string 成功返回原站点相对路径，失败返回 ''
     */
    public function replaceByNumber($number, $file = null, $options = null)
    {
        $options = ($options instanceof AttachmentUploadOptions) ? $options : AttachmentUploadOptions::create();
        if (!($file instanceof UploadedFile) || !$file->isValid()) {
            return '';
        }

        $row = $this->repository->findByNumber((string) $number);
        if (empty($row) || !isset($row['module'], $row['file'])) {
            return '';
        }

        // 可编辑扩展白名单：与前端 douCrop.editableExt 保持一致（gif/bmp/svg 不支持替换）
        $oldPath = (string) $row['file'];
        $oldExt = strtolower(pathinfo($oldPath, PATHINFO_EXTENSION));
        if (!in_array($oldExt, array('jpg', 'jpeg', 'png', 'webp'), true)) {
            return '';
        }

        $disk = $this->resolveDisk($row['module'], $options);
        $quality = $this->resolveImageQuality($disk, $options);
        $thumbSub = (string) $disk->getConfig('thumb_directory', '');

        $diskRoot = rtrim((string) $disk->getConfig('root', ''), '/');
        $relativePathInDisk = (strpos($oldPath, $diskRoot . '/') === 0) ? substr($oldPath, strlen($diskRoot . '/')) : $oldPath;
        $absoluteTarget = $disk->path($relativePathInDisk);
        $absoluteDir = dirname($absoluteTarget);
        if (FileHelper::permission($absoluteDir . '/') !== 'write' || !file_exists($absoluteTarget)) {
            return '';
        }

        $imgWidth = $options->getImageWidth();
        $srcExt = $this->detectUploadImageExt($file);
        $needTranscode = ($srcExt !== '' && !$this->isSameImageExt($srcExt, $oldExt));

        $backup = $absoluteTarget . '.dou_bak';
        if (!@rename($absoluteTarget, $backup)) {
            return '';
        }

        if ($needTranscode) {
            $tmpDir = str_replace('\\', '/', rtrim(sys_get_temp_dir(), '/\\'));
            $tmpBase = 'dou_rep_' . str_replace('.', '', uniqid('', true)) . '.' . $srcExt;
            if (!$file->move($tmpDir, $tmpBase)) {
                @rename($backup, $absoluteTarget);

                return '';
            }
            $tmpAbs = $tmpDir . '/' . $tmpBase;
            $ok = $this->images->transcode($tmpAbs, $absoluteTarget, $imgWidth, 0, $quality);
            @unlink($tmpAbs);
            if (!$ok) {
                @rename($backup, $absoluteTarget);

                return '';
            }
            @unlink($backup);
        } else {
            if (!$file->move($absoluteDir, basename($absoluteTarget))) {
                @rename($backup, $absoluteTarget);

                return '';
            }
            @unlink($backup);
            if ($imgWidth > 0) {
                $this->images->resize($absoluteTarget, $absoluteTarget, $imgWidth, 0, $quality);
            }
        }

        $watermark = $options->getWatermark();
        if ($watermark !== '') {
            $opts = $this->buildWatermarkOptions($watermark);
            $this->images->watermark($absoluteTarget, $absoluteTarget, $opts, $quality);
        }

        // 该图已存在缩略图时按后台缩略图尺寸重建（口径同「更新商品缩略图」）
        $thumbSize = 0;
        $thumbW = (int) Config::get('site.thumb_width', 0);
        $thumbH = (int) Config::get('site.thumb_height', 0);
        $thumbAbs = $this->buildThumbAbsolute($absoluteTarget, $thumbSub);
        if (($thumbW > 0 || $thumbH > 0) && file_exists($thumbAbs)) {
            if ($this->images->thumb($absoluteTarget, $thumbAbs, $thumbW, $thumbH, $quality)) {
                clearstatcache();
                $thumbSize = file_exists($thumbAbs) ? (int) filesize($thumbAbs) : 0;
            }
        }

        clearstatcache();
        $size = file_exists($absoluteTarget) ? (int) filesize($absoluteTarget) : 0;
        $this->repository->updateByNumber((string) $number, array(
            'size' => $size,
            'thumb_size' => $thumbSize,
            'last_used_at' => date('Y-m-d H:i:s'),
        ));

        return $oldPath;
    }

    /**
     * 上传文件的真实图片扩展名（优先客户端名，否则 sniff tmp）。
     *
     * @param UploadedFile $file
     * @return string
     */
    private function detectUploadImageExt($file)
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if (in_array($ext, array('jpg', 'png', 'webp', 'gif'), true)) {
            return $ext;
        }
        $info = @getimagesize($file->getPathname());
        if (!is_array($info) || !isset($info[2])) {
            return '';
        }
        if ($info[2] === 2) {
            return 'jpg';
        }
        if ($info[2] === 3) {
            return 'png';
        }
        if ($info[2] === 18) {
            return 'webp';
        }
        if ($info[2] === 1) {
            return 'gif';
        }

        return '';
    }

    /**
     * jpg / jpeg 视为同一扩展。
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    private function isSameImageExt($a, $b)
    {
        $a = strtolower((string) $a);
        $b = strtolower((string) $b);
        if ($a === 'jpeg') {
            $a = 'jpg';
        }
        if ($b === 'jpeg') {
            $b = 'jpg';
        }

        return $a !== '' && $a === $b;
    }

    /**
     * 仅落盘到指定磁盘 + 子目录，不写 dou_file 表。
     *
     * @param UploadedFile|null $file 未选文件时调用方可直接传 {@see UploadedFile::fromGlobals} 的 null 结果
     * @param Disk|string $disk disk 实例（来自 Storage::build(...)）或已注册磁盘名
     * @param string $directory disk root 内子目录
     * @param string $basename 不含扩展名，空 → 随机
     * @param string $type
     * @param AttachmentUploadOptions|null $options
     * @return string 仅文件名（不含目录），失败抛 DomainException 或返回空
     */
    public function storeToDirectory($file = null, $disk = '', $directory = '', $basename = '', $type = 'main', $options = null)
    {
        $options = ($options instanceof AttachmentUploadOptions) ? $options : AttachmentUploadOptions::create();
        if (!($file instanceof UploadedFile) || !$file->isValid()) {
            return $this->fail($type, lang('file_empty'));
        }

        $diskObj = $disk instanceof Disk ? $disk : $this->storage->disk($disk);
        $allow = $this->resolveAllowExtensions($diskObj, $options);
        $maxKb = $this->resolveUploadMaxKb($diskObj, $options);

        if ($basename === '') {
            $basename = Str::randomByType('number', 6, time());
        }
        $extension = $file->getClientOriginalExtension();
        if (!$this->extensionAllowed($extension, $allow)) {
            return $this->fail($type, lang('file_support') . $allow . lang('file_support_no') . $extension);
        }
        if ($file->getSizeKb() > $maxKb) {
            return $this->fail($type, lang('file_out_size') . $maxKb . 'KB');
        }
        $finalName = $basename . '.' . $extension;
        $relativeInDisk = ($directory !== '' ? trim($directory, '/') . '/' : '') . $finalName;
        $absoluteTarget = $diskObj->path($relativeInDisk);
        $absoluteDir = dirname($absoluteTarget);
        if (!is_dir($absoluteDir)) {
            @mkdir($absoluteDir, 0777, true);
        }
        if (FileHelper::permission($absoluteDir . '/') !== 'write') {
            return $this->fail($type, lang('file_dir_wrong'));
        }
        if (!$file->move($absoluteDir, basename($absoluteTarget))) {
            return $this->fail($type, $this->formatFileWrong());
        }

        return $finalName;
    }

    /**
     * 大文件分块上传。
     *
     * @param mixed $module
     * @param mixed $itemId
     * @param string $fileField
     * @param string $type
     * @param string $customFilename
     * @param string $allowFileType
     * @param string|null $diskName
     * @param string $uploaderKind 'admin' / 'user' / 'work'，owned 写入必填
     * @param int $uploaderId 对应身份表主键
     * @return array
     */
    public function chunkedStore($module, $itemId, $fileField = 'file', $type = 'main', $customFilename = '', $allowFileType = 'zip,rar', $diskName = null, $uploaderKind = '', $uploaderId = 0)
    {
        $disk = $diskName !== null ? $this->storage->disk($diskName) : $this->resolveDisk($module, AttachmentUploadOptions::create());
        $ownedCtx = array(
            'uploader_type' => $this->resolveUploaderType($uploaderKind),
            'uploader_id' => (int) $uploaderId,
        );

        return $this->chunkedHandler->handle($disk, $module, $itemId, $fileField, $type, $customFilename, $allowFileType, array(), $ownedCtx);
    }

    /**
     * 大文件分片上传草稿版：与 {@see chunkedStore()} 同语义，但以 draft 行写入 dou_file。
     *
     * 新建场景（item_id 缺失或为 0 + draft_token 非空）调用，业务表写入拿到真 id 后由
     * claimByToken 一次性认领。
     *
     * @param string $module
     * @param string $identityKind 'admin' / 'user' / 'work'
     * @param int $identityId
     * @param string $draftToken
     * @param string $fileField
     * @param string $type
     * @param string $customFilename
     * @param string $allowFileType
     * @param string|null $diskName
     * @return array
     */
    public function chunkedStoreDraft($module, $identityKind, $identityId, $draftToken, $fileField = 'file', $type = 'main', $customFilename = '', $allowFileType = 'zip,rar', $diskName = null)
    {
        $disk = $diskName !== null ? $this->storage->disk($diskName) : $this->resolveDisk($module, AttachmentUploadOptions::create());
        $uploaderType = $this->resolveUploaderType($identityKind);

        return $this->chunkedHandler->handle($disk, $module, 0, $fileField, $type, $customFilename, $allowFileType, array(
            'uploader_type' => $uploaderType,
            'uploader_id' => (int) $identityId,
            'draft_token' => (string) $draftToken,
            'draft_expire_at' => time() + self::DRAFT_LIFETIME_SECONDS,
        ));
    }

    // -----------------------------------------------------------------
    // 内容拉图
    // -----------------------------------------------------------------

    /**
     * 处理富文本中的远程图：扫描 → 下载 → 替换为本地链接。
     *
     * @param mixed $module
     * @param mixed $itemId
     * @param string $content
     * @param string $type
     * @param string $folder
     * @param AttachmentUploadOptions|null $options
     * @return string
     */
    public function storeContentImages($module, $itemId, $content, $type = 'content', $folder = '', $options = null)
    {
        $options = ($options instanceof AttachmentUploadOptions) ? $options : AttachmentUploadOptions::create();
        $ownedMeta = array(
            'uploader_type' => $this->requireUploaderType($options),
            'uploader_id' => (int) $options->getUploaderId(),
        );
        $disk = $this->resolveDisk($module, $options);

        return $this->contentImporter->importContent($disk, $module, $itemId, $type, $content, $folder, $options, array(), $ownedMeta);
    }

    /**
     * 单张远程图下载。
     *
     * @param mixed $module
     * @param mixed $itemId
     * @param string $remoteUrl
     * @param string $type
     * @param string $folder
     * @param string $customFilename
     * @param AttachmentUploadOptions|null $options
     * @param string $outputFormat 'path' 返回 URL；'number' 返回 file number
     * @return string|null
     */
    public function storeFromUrl($module, $itemId, $remoteUrl, $type = 'content', $folder = '', $customFilename = '', $options = null, $outputFormat = 'path')
    {
        $options = ($options instanceof AttachmentUploadOptions) ? $options : AttachmentUploadOptions::create();
        $ownedMeta = array(
            'uploader_type' => $this->requireUploaderType($options),
            'uploader_id' => (int) $options->getUploaderId(),
        );
        $disk = $this->resolveDisk($module, $options);

        return $this->contentImporter->downloadOne($disk, $remoteUrl, $module, $itemId, $type, $folder, $customFilename, $options, $outputFormat, array(), $ownedMeta);
    }

    // -----------------------------------------------------------------
    // Draft 上传 + 草稿认领
    // -----------------------------------------------------------------

    /**
     * 草稿上传：写入 dou_file 时 `item_id=0` / `status='draft'` /
     * `{kind}_id=$identityId` / `draft_token=$draftToken`，等待业务表写入后调
     * {@see claimByToken} 把 item_id 落到真实主键。
     *
     * 约束：
     *   - draft 一定是新行，不读业务表 existingNumber、不走 update 分支；
     *   - 必须提供 identity + token，否则抛 DomainException。
     *
     * @param mixed $module
     * @param UploadedFile|null $file
     * @param string $identityKind 'admin' / 'user' / 'work'
     * @param int $identityId 对应身份表主键
     * @param string $draftToken 表单期生成的 draft_token（与认领时一致）
     * @param string $type
     * @param AttachmentUploadOptions|null $options
     * @return string 分配的 file number；上传缺省时返回 ''
     */
    public function storeDraft($module, $file = null, $identityKind = '', $identityId = 0, $draftToken = '', $type = 'main', $options = null)
    {
        $options = ($options instanceof AttachmentUploadOptions) ? $options : AttachmentUploadOptions::create();
        if (!($file instanceof UploadedFile) || !$file->isValid()) {
            return '';
        }
        if ($draftToken === '' || (int) $identityId <= 0) {
            throw new DomainException('draft_token and identityId are required for storeDraft');
        }
        $uploaderType = $this->resolveUploaderType($identityKind);

        $disk = $this->resolveDisk($module, $options);
        $allow = $this->resolveAllowExtensions($disk, $options);
        $maxKb = $this->resolveUploadMaxKb($disk, $options);
        $quality = $this->resolveImageQuality($disk, $options);
        $thumbSub = (string) $disk->getConfig('thumb_directory', '');

        $dirOpt = $options->getDirectory();
        $directoryRelative = $dirOpt !== '' ? trim($dirOpt, '/') . '/' : '';
        $basename = $this->resolveBasename($options, null);

        $extension = $file->getClientOriginalExtension();
        if ($extension === '') {
            $extension = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
        }
        if (!$this->extensionAllowed($extension, $allow)) {
            return $this->fail($type, lang('file_support') . $allow . lang('file_support_no') . $extension);
        }
        if ($file->getSizeKb() > $maxKb) {
            return $this->fail($type, lang('file_out_size') . $maxKb . 'KB');
        }

        $finalFileName = $basename . '.' . $extension;
        $relativePathInDisk = $directoryRelative . $finalFileName;
        $absoluteTarget = $disk->path($relativePathInDisk);
        $absoluteDir = dirname($absoluteTarget);
        if (!is_dir($absoluteDir)) {
            @mkdir($absoluteDir, 0777, true);
        }
        if (FileHelper::permission($absoluteDir . '/') !== 'write') {
            return $this->fail($type, lang('file_dir_wrong'));
        }
        if (file_exists($absoluteTarget)) {
            @unlink($absoluteTarget);
        }
        if (!$file->move($absoluteDir, basename($absoluteTarget))) {
            return $this->fail($type, $this->formatFileWrong());
        }

        $imgWidth = $options->getImageWidth();
        if ($imgWidth > 0) {
            $this->images->resize($absoluteTarget, $absoluteTarget, $imgWidth, 0, $quality);
        }
        $watermark = $options->getWatermark();
        if ($watermark !== '') {
            $opts = $this->buildWatermarkOptions($watermark);
            $this->images->watermark($absoluteTarget, $absoluteTarget, $opts, $quality);
        }
        $thumbW = $options->getThumbWidth();
        $thumbH = $options->getThumbHeight();
        $thumbSize = 0;
        if ($thumbW > 0 || $thumbH > 0) {
            $thumbAbs = $this->buildThumbAbsolute($absoluteTarget, $thumbSub);
            if ($this->images->thumb($absoluteTarget, $thumbAbs, $thumbW, $thumbH, $quality)) {
                clearstatcache();
                $thumbSize = file_exists($thumbAbs) ? (int) filesize($thumbAbs) : 0;
            }
        }

        $diskRoot = (string) $disk->getConfig('root', '');
        $relativeToSite = $diskRoot . $relativePathInDisk;
        clearstatcache();
        $size = file_exists($absoluteTarget) ? (int) filesize($absoluteTarget) : 0;
        $now = date('Y-m-d H:i:s');

        $number = $this->repository->allocateNumber();
        $this->repository->insert(array(
            'number' => $number,
            'file' => $relativeToSite,
            'module' => $module,
            'item_id' => 0,
            'type' => $type,
            'size' => $size,
            'thumb_size' => $thumbSize,
            'last_used_at' => $now,
            'created_at' => $now,
            'uploader_type' => $uploaderType,
            'uploader_id' => (int) $identityId,
            'status' => 'draft',
            'draft_token' => $draftToken,
            'draft_expire_at' => date('Y-m-d H:i:s', time() + self::DRAFT_LIFETIME_SECONDS),
        ));

        return $number;
    }

    /**
     * 富文本远程图 draft 路径：扫描 → 下载 → 写为 draft dou_file 行 → 替换为本地链接。
     *
     * 与 storeContentImages 的差异同 storeDraft vs store：dou_file 行进 draft 状态，
     * 等业务 INSERT 后由 {@see claimByToken} 一并 claim。
     *
     * @param mixed $module
     * @param string $content
     * @param string $identityKind 'admin' / 'user' / 'work'
     * @param int $identityId
     * @param string $draftToken
     * @param string $type
     * @param string $folder
     * @param AttachmentUploadOptions|null $options
     * @return string
     */
    public function storeDraftContentImages($module, $content, $identityKind = '', $identityId = 0, $draftToken = '', $type = 'content', $folder = '', $options = null)
    {
        $options = ($options instanceof AttachmentUploadOptions) ? $options : AttachmentUploadOptions::create();
        if ($draftToken === '' || (int) $identityId <= 0) {
            throw new DomainException('draft_token and identityId are required for storeDraftContentImages');
        }
        $uploaderType = $this->resolveUploaderType($identityKind);
        $disk = $this->resolveDisk($module, $options);

        return $this->contentImporter->importContent($disk, $module, 0, $type, $content, $folder, $options, array(
            'uploader_type' => $uploaderType,
            'uploader_id' => (int) $identityId,
            'draft_token' => $draftToken,
            'draft_expire_at' => date('Y-m-d H:i:s', time() + self::DRAFT_LIFETIME_SECONDS),
        ));
    }

    /**
     * 按 draft_token + 当前身份认领草稿附件：把 dou_file 行 item_id 落到真业务主键、status='owned'。
     *
     * 调用约束：业务表 INSERT 拿到 $itemId 后立刻调用，传入与 storeDraft 时一致的 token 与身份。
     * 安全：SQL WHERE 同时校验 module + draft_token + {kind}_id + status='draft' 四条件，
     * 跨身份越权 claim 不可能命中。
     *
     * @param mixed $module
     * @param string $draftToken
     * @param string $itemId 真实业务主键（按字符串处理：share_sn 等零填充 / 长数字 SN 不能强转 int）
     * @param string $identityKind 'admin' / 'user' / 'work'
     * @param int $identityId
     * @return array 命中的 number 列表
     */
    public function claimByToken($module, $draftToken, $itemId, $identityKind = '', $identityId = 0)
    {
        $uploaderType = $this->resolveUploaderType($identityKind);

        return $this->repository->claimByToken($module, $draftToken, (string) $itemId, $uploaderType, (int) $identityId);
    }

    /**
     * 懒 GC：清理某身份在某模块下「draft_expire_at < now」的过期 drafts。
     *
     * 调用时机建议：业务模块 controller `create()` 渲染表单前调用一次，
     * 把用户上次未提交的过期附件一并清理（物理文件 + 缩略图 + dou_file 行）。
     * TTL 由 storeDraft 写入的 draft_expire_at 列承载（默认 {@see DRAFT_LIFETIME_SECONDS}）。
     *
     * @param string $identityKind 'admin' / 'user' / 'work'
     * @param int $identityId
     * @param mixed $module
     * @return int 删除的附件条数
     */
    public function cleanupUserDrafts($identityKind, $identityId, $module)
    {
        $uploaderType = $this->resolveUploaderType($identityKind);

        return $this->repository->cleanupUserDrafts($uploaderType, (int) $identityId, $module);
    }

    /**
     * 生成新的 draft_token 字符串：用于表单期挂在隐藏 input 里、随上传与提交透传。
     *
     * 设计：32 位 md5，与身份强绑定（identityKind + identityId + microtime 进入 hash），
     * 不暴露明文身份信息，避免被反推或猜测。同一身份每次调用都不同。
     *
     * @param string $identityKind 'admin' / 'user' / 'work'（参与 hash，不写入 token 明文）
     * @param int $identityId
     * @return string 32 位 token
     */
    public function newDraftToken($identityKind, $identityId)
    {
        return md5(uniqid((string) $identityKind . '_' . (int) $identityId . '_', true) . microtime(true));
    }

    /**
     * identity kind → dou_file uploader_type 取值（admin/user/work）。
     *
     * dou_file 采用多态对 uploader_type + uploader_id，identityKind 本身即 uploader_type；
     * 此处仅做合法性校验并返回规范化的类型字符串。
     *
     * @param string $identityKind
     * @return string
     */
    private function resolveUploaderType($identityKind)
    {
        switch ((string) $identityKind) {
            case 'admin':
            case 'user':
            case 'work':
                return (string) $identityKind;
            default:
                throw new DomainException('Invalid identityKind for AttachmentService: ' . $identityKind);
        }
    }

    /**
     * owned 写入路径强制要求 uploader 身份（fail-fast）：调用方须经
     * {@see AttachmentUploadOptions::withUploader} 显式声明 admin/user/work + id。
     *
     * 缺失即抛异常，杜绝 owned 行静默落 DB 列默认 'admin' 的隐患。
     *
     * @param AttachmentUploadOptions $options
     * @return string 规范化后的 uploader_type
     */
    private function requireUploaderType(AttachmentUploadOptions $options)
    {
        $kind = (string) $options->getUploaderKind();
        if ($kind === '') {
            throw new DomainException('Uploader identity is required: call AttachmentUploadOptions::withUploader($kind, $id) before owned store().');
        }

        return $this->resolveUploaderType($kind);
    }

    // -----------------------------------------------------------------
    // 元数据
    // -----------------------------------------------------------------

    /**
     * 按 number 删除（含物理文件、缩略图、表记录）。
     *
     * @param string $number
     * @return void
     */
    public function delete($number)
    {
        $this->repository->deleteByNumber($number);
    }

    /**
     * 按 number 改写物理文件主名。
     *
     * @param string $number
     * @param string $newBasename
     * @return void
     */
    public function renameStoredFile($number, $newBasename)
    {
        $this->repository->renameBasename($number, $newBasename);
    }

    /**
     * 把 number 对应文件迁到新目录（相对站点根，以 / 结尾）。
     *
     * @param string $number
     * @param string $newDirRelative
     * @return bool
     */
    public function moveStoredFileToDirectory($number, $newDirRelative)
    {
        return $this->repository->moveToDirectory($number, $newDirRelative);
    }

    // -----------------------------------------------------------------
    // URL & 画廊
    // -----------------------------------------------------------------

    /**
     * 单 number → URL。
     *
     * @param mixed $number
     * @param bool $thumb
     * @return string
     */
    public function url($number, $thumb = false)
    {
        return $this->urls->url($number, $thumb);
    }

    /**
     * 批量 → URL 映射。
     *
     * @param mixed $numbers
     * @param bool $thumb
     * @return array
     */
    public function urlBatch($numbers, $thumb = false)
    {
        return $this->urls->urlBatch($numbers, $thumb);
    }

    /**
     * 缓存尾。
     *
     * @param string $fileUpdateTime
     * @return string
     */
    public function cacheTag($fileUpdateTime = '')
    {
        return $this->urls->cacheTag($fileUpdateTime);
    }

    /**
     * 画廊列表。
     *
     * @param mixed $module
     * @param mixed $itemId
     * @param mixed $type
     * @param bool $arrayMode
     * @return mixed
     */
    public function gallery($module, $itemId, $type, $arrayMode = false)
    {
        return $this->gallery->galleryList($module, $itemId, $type, $arrayMode);
    }

    /**
     * 新建场景画廊：按当前身份 + draft_token 列出 draft 缩略图。
     *
     * 与 {@see gallery()} 平行接口；用于 FileController::box 的 fileBox 单图上传后
     * 返回前端可见的 gallery li 列表，但只暴露「当前 admin/user/work 自己」上传的部分。
     *
     * @param string $module
     * @param string $draftToken
     * @param string $identityKind 'admin' / 'user' / 'work'
     * @param int $identityId
     * @param string $type gallery / main / content
     * @param bool $arrayMode
     * @return mixed
     */
    public function galleryByDraft($module, $draftToken, $identityKind, $identityId, $type, $arrayMode = false)
    {
        $uploaderType = $this->resolveUploaderType($identityKind);

        return $this->gallery->galleryListByDraft($module, (string) $draftToken, $uploaderType, (int) $identityId, $type, $arrayMode);
    }

    /**
     * 画廊首图。
     *
     * @param mixed $module
     * @param mixed $itemId
     * @return string
     */
    public function galleryFirst($module, $itemId)
    {
        return $this->gallery->galleryFirst($module, $itemId);
    }

    /**
     * 批量画廊首图映射。
     *
     * @param mixed $module
     * @param mixed $ids
     * @return array
     */
    public function galleryFirstMap($module, $ids)
    {
        return $this->gallery->galleryFirstMap($module, $ids);
    }

    /**
     * 暴露 repository 给少量需要直接 CRUD 的调用方（不鼓励常规使用）。
     *
     * @return AttachmentRepository
     */
    public function repository()
    {
        return $this->repository;
    }

    // -----------------------------------------------------------------
    // 内部工具
    // -----------------------------------------------------------------

    /**
     * 解析 disk：options.disk 优先；否则按 module 名查 filesystems.disks。
     *
     * @param mixed $module
     * @param AttachmentUploadOptions $options
     * @return Disk
     */
    private function resolveDisk($module, AttachmentUploadOptions $options)
    {
        $name = $options->getDisk() !== '' ? $options->getDisk() : (string) $module;

        return $this->storage->disk($name);
    }

    /**
     * 解析允许扩展名（options → disk → 默认）。
     *
     * @param Disk $disk
     * @param AttachmentUploadOptions $options
     * @return string
     */
    private function resolveAllowExtensions(Disk $disk, AttachmentUploadOptions $options)
    {
        if ($options->getAllowExtensions() !== '') {
            return $options->getAllowExtensions();
        }
        $cfg = (string) $disk->getConfig('allow_extensions', '');

        // 默认白名单不含 svg：SVG 可内嵌 <script>/onload，作为图片直链访问会触发存储型 XSS。
        // 确需 svg 的磁盘可在 filesystems.disks.{name}.allow_extensions 显式开启。
        return $cfg !== '' ? $cfg : 'jpg,jpeg,gif,png,webp,ico';
    }

    /**
     * 解析上传 KB 上限。
     *
     * @param Disk $disk
     * @param AttachmentUploadOptions $options
     * @return int
     */
    private function resolveUploadMaxKb(Disk $disk, AttachmentUploadOptions $options)
    {
        if ($options->getUploadMaxKb() > 0) {
            return $options->getUploadMaxKb();
        }
        $cfg = (int) $disk->getConfig('upload_max_kb', 0);

        return $cfg > 0 ? $cfg : 2048;
    }

    /**
     * 解析图片输出质量。
     *
     * @param Disk $disk
     * @param AttachmentUploadOptions $options
     * @return int
     */
    private function resolveImageQuality(Disk $disk, AttachmentUploadOptions $options)
    {
        if ($options->getImageQuality() > 0) {
            return $options->getImageQuality();
        }
        $cfg = (int) $disk->getConfig('image_quality', 0);
        if ($cfg > 0) {
            return $cfg;
        }
        $site = (int) Config::get('site.quality', 0);

        return $site > 0 ? $site : 90;
    }

    /**
     * 决定文件主名：options.basename → 已存在的文件主名（续传时复用） → 时间戳 + 随机串。
     *
     * @param AttachmentUploadOptions $options
     * @param array|null $existingRow
     * @return string
     */
    private function resolveBasename(AttachmentUploadOptions $options, $existingRow)
    {
        if ($options->getBasename() !== '') {
            return $options->getBasename();
        }
        if (is_array($existingRow) && isset($existingRow['file'])) {
            $oldName = FileHelper::filename((string) $existingRow['file']);
            if ($oldName !== '' && $oldName !== false) {
                return $oldName;
            }
        }

        return $this->repository->allocateRandomBasename();
    }

    /**
     * 给定主图绝对路径与 thumb 子目录，计算缩略图绝对路径。
     *
     * @param string $absolutePath
     * @param string $thumbSub
     * @return string
     */
    private function buildThumbAbsolute($absolutePath, $thumbSub)
    {
        $dir = dirname($absolutePath);
        $name = basename($absolutePath);
        $dot = strrpos($name, '.');
        $base = $dot !== false ? substr($name, 0, $dot) : $name;
        $ext = $dot !== false ? substr($name, $dot + 1) : '';
        $sub = $thumbSub === '' ? '' : rtrim($thumbSub, '/') . '/';
        $thumbName = $base . '_thumb' . ($ext !== '' ? '.' . $ext : '');

        return $dir . '/' . $sub . $thumbName;
    }

    /**
     * 校验扩展名是否在允许清单中。
     *
     * @param string $extension
     * @param string $allow
     * @return bool
     */
    private function extensionAllowed($extension, $allow)
    {
        $extension = strtolower((string) $extension);
        if ($extension === '') {
            return false;
        }
        $allowList = array_map('trim', explode(',', strtolower((string) $allow)));

        return in_array($extension, $allowList, true);
    }

    /**
     * 默认水印选项：storage/watermark/watermark.png 存在 → img；否则 → text。
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

    /**
     * 有效上传上限（KB）：PHP ini 与 filesystems.upload_defaults.upload_max_kb 取小。
     *
     * @return int
     */
    public function effectiveUploadMaxKb()
    {
        $phpKb = UploadedFile::phpUploadMaxKb();
        $cfgKb = (int) Config::get('filesystems.upload_defaults.upload_max_kb', 2048);
        if ($cfgKb <= 0) {
            $cfgKb = 2048;
        }
        if ($phpKb <= 0) {
            return $cfgKb;
        }

        return $phpKb < $cfgKb ? $phpKb : $cfgKb;
    }

    /**
     * file_wrong 把 d% 换成 {@see effectiveUploadMaxKb()}。
     *
     * @return string
     */
    public function formatFileWrong()
    {
        return UploadedFile::formatFileWrong($this->effectiveUploadMaxKb());
    }

    /**
     * 上传报错链路：content 走 die()，其他走 message()->respond() 抛异常。
     *
     * @param string $type
     * @param string $message
     * @return string ''（仅在 content 路径执行不到这里，因为前面 die 了）
     */
    private function fail($type, $message)
    {
        if ($type === 'content') {
            die($message);
        }
        throw new DomainException($message);
    }

    /**
     * 解析业务表里用于「读已有 number」的列名。
     *
     * HTML 字段名由 UploadedFile 自行解析；业务列名走 options.withBusinessField()，
     * 未显式时按 type 推断（main → image；gallery → 不读）。
     *
     * @param string $type
     * @param AttachmentUploadOptions $options
     * @return string
     */
    private function resolveBusinessField($type, AttachmentUploadOptions $options)
    {
        if ($options->getBusinessField() !== '') {
            return $options->getBusinessField();
        }
        if ($type === 'main') {
            return 'image';
        }
        if ($type === 'gallery') {
            return '';
        }

        return $type;
    }
}
