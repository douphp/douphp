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
use Dou\Core\Filesystem\PathNormalizer;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 大文件分块上传。
 *
 * 由调用方显式传入 blob_num / total_blob_num / file_name / sql_link_url 与 UploadedFile，
 * 合并分块后写 dou_file 表并返回 ajax payload。
 */
class ChunkedUploadHandler
{
    /** @var AttachmentRepository */
    private $repository;

    /**
     * @param AttachmentRepository $repository
     */
    public function __construct(AttachmentRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * 处理一个分块请求。
     *
     * 当 $draftCtx 不为空时（包含 uploader_type/uploader_id/token/expire），该附件以 draft 行
     * 写入（item_id=0、status='draft' + token + uploader_type + uploader_id + expire_at），业务表
     * 写入后由 claimByToken 一次性认领；否则按 owned 路径写入。
     *
     * @param Disk $disk
     * @param mixed $module
     * @param mixed $itemId
     * @param string $fileField
     * @param string $type
     * @param string $customFilename
     * @param string $allowFileType
     * @param array $draftCtx 可选；含 'uploader_type'(string)、'uploader_id'(int)、'draft_token'(string)、'draft_expire_at'(int)
     * @param array $ownedCtx owned 写入路径的 uploader 身份，键：uploader_type / uploader_id
     * @param array $chunkInput 分片入参：blob_num / total_blob_num / file_name / sql_link_url / file(UploadedFile)
     * @return array
     */
    public function handle(Disk $disk, $module, $itemId, $fileField, $type, $customFilename, $allowFileType, array $draftCtx = array(), array $ownedCtx = array(), array $chunkInput = array())
    {
        $isDraft = !empty($draftCtx['draft_token']) && !empty($draftCtx['uploader_type']) && (int) (isset($draftCtx['uploader_id']) ? $draftCtx['uploader_id'] : 0) > 0;
        if ($isDraft) {
            $itemId = 0;
        }
        $data = array('html' => '');

        $blobNumRaw = isset($chunkInput['blob_num']) ? $chunkInput['blob_num'] : '';
        $totalBlobNumRaw = isset($chunkInput['total_blob_num']) ? $chunkInput['total_blob_num'] : '';
        if (!Check::number($blobNumRaw) || !Check::number($totalBlobNumRaw)) {
            return $data;
        }
        $blobNum = $blobNumRaw;
        $totalBlobNum = $totalBlobNumRaw;
        $sqlLinkUrl = isset($chunkInput['sql_link_url']) ? $chunkInput['sql_link_url'] : '';
        $fileName = isset($chunkInput['file_name']) ? $chunkInput['file_name'] : '';

        $nameParts = explode('.', $fileName);
        $count = count($nameParts);
        $fileType = $count > 0 ? $nameParts[$count - 1] : '';

        // 严格白名单成员判定，替代 stripos 子串匹配（'pg' 会被 'jpg' 命中之类的松散匹配）。
        $allowList = array_map('trim', explode(',', strtolower((string) $allowFileType)));
        if ($fileType === '' || !in_array(strtolower($fileType), $allowList, true)) {
            $data['wrong'] = lang('file_support') . $allowFileType . lang('file_support_no') . $fileType;
            return $data;
        }

        $fileDir = (string) $disk->getConfig('root', '');
        $fullFileDir = $disk->path('');
        if (substr($fullFileDir, -1) !== '/') {
            $fullFileDir .= '/';
        }
        if (!is_dir($fullFileDir)) {
            @mkdir($fullFileDir, 0777, true);
        }

        if (!empty($sqlLinkUrl)) {
            $sqlDir = dirname($sqlLinkUrl) . '/';
            if ($sqlDir === ROOT_URL . $fileDir) {
                // 复用原文件主名以原地覆盖；扩展名强制取经白名单校验的 $fileType，
                // 杜绝借 sql_link_url 改写扩展名（如 file_name=x.mp4 过校验却落盘为 evil.php）的 RCE 旁路。
                $base = pathinfo(basename($sqlLinkUrl), PATHINFO_FILENAME);
                $fullFileName = $base . '.' . $fileType;
            } else {
                $fullFileName = $customFilename . '.' . $fileType;
            }
        } else {
            $fullFileName = $customFilename . '.' . $fileType;
        }

        $fileAddress = $fullFileDir . $fullFileName;
        $relativePath = $fileDir . $fullFileName;

        $record = DB::table('file')->where('file', $relativePath)->find();
        if ($record) {
            $number = $record['number'];
            $action = 'update';
        } else {
            $number = $this->repository->allocateNumber();
            $action = 'insert';
        }

        $uploaded = isset($chunkInput['file']) ? $chunkInput['file'] : null;
        if (!$uploaded instanceof UploadedFile) {
            return $data;
        }
        if (!$uploaded->move($fullFileDir, $fullFileName . '__' . $blobNum)) {
            $data['wrong'] = attachment()->formatFileWrong();
            return $data;
        }

        if ($blobNum == $totalBlobNum) {
            $fp = fopen($fileAddress, 'w+');
            if ($fp === false) {
                $data['wrong'] = attachment()->formatFileWrong();
                return $data;
            }
            for ($i = 1; $i <= $totalBlobNum; $i++) {
                $chunk = file_get_contents($fileAddress . '__' . $i);
                if ($chunk !== false) {
                    fwrite($fp, $chunk);
                }
            }
            fclose($fp);
            for ($i = 1; $i <= $totalBlobNum; $i++) {
                @unlink($fileAddress . '__' . $i);
            }

            clearstatcache();
            $size = filesize(PathNormalizer::absoluteFromSiteRoot($relativePath));
            $now = date('Y-m-d H:i:s');
            if ($action === 'insert') {
                $row = array(
                    'number' => $number,
                    'file' => $relativePath,
                    'module' => $module,
                    'item_id' => $itemId,
                    'type' => $type,
                    'size' => $size,
                    'thumb_size' => 0,
                    'last_used_at' => $now,
                    'created_at' => $now,
                );
                if ($isDraft) {
                    $row['uploader_type'] = (string) $draftCtx['uploader_type'];
                    $row['uploader_id'] = (int) $draftCtx['uploader_id'];
                    $row['status'] = 'draft';
                    $row['draft_token'] = (string) $draftCtx['draft_token'];
                    $row['draft_expire_at'] = !empty($draftCtx['draft_expire_at'])
                        ? date('Y-m-d H:i:s', (int) $draftCtx['draft_expire_at'])
                        : null;
                } elseif (!empty($ownedCtx['uploader_type'])) {
                    $row['uploader_type'] = (string) $ownedCtx['uploader_type'];
                    $row['uploader_id'] = isset($ownedCtx['uploader_id']) ? (int) $ownedCtx['uploader_id'] : 0;
                    $row['status'] = 'owned';
                }
                $this->repository->insert($row);
            } else {
                $this->repository->updateByNumber($number, array(
                    'file' => $relativePath,
                    'size' => $size,
                    'thumb_size' => 0,
                    'last_used_at' => $now,
                ));
                $this->repository->touchUpdateTime();
            }

            $data['code'] = 2;
            $data['msg'] = 'success';
            $data['file_path'] = ROOT_URL . $fileDir . $fullFileName;

            if (stripos('wmv,avi,mp4,flv', $fileType) !== false) {
                $data['html'] = '<em style="display:none">video</em><video src="' . $data['file_path'] . '" style="float:none" controls="" preload="" autoplay="true" muted="muted" data-file="' . $number . '"></video>';
            } elseif (stripos('jpg,jpeg,gif,png,webp', $fileType) !== false) {
                $data['html'] = '<img src="' . $data['file_path'] . '" data-file="' . $number . '" />';
            } elseif (stripos('mp3', $fileType) !== false) {
                $data['html'] = '<audio controls=""><source src="' . $data['file_path'] . '" type="audio/mpeg" data-file="' . $number . '"/></audio>';
            } else {
                $data['html'] = '<a class="btn" href="' . $data['file_path'] . '" target="_blank" data-file="' . $number . '">' . lang('down') . '</a>';
            }
        } else {
            if (file_exists($fileAddress . '__' . $blobNum)) {
                $data['code'] = 1;
                $data['msg'] = 'waiting';
                $data['file_path'] = '';
            }
        }

        return $data;
    }
}
