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
use Dou\Core\Filesystem\PathNormalizer;
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * `dou_file` 表元数据仓储。
 *
 * 仅做表内 CRUD 与 `.file` 号分配；不感知具体磁盘 / 上传策略。
 */
class AttachmentRepository
{
    /**
     * 分配新的全局唯一 `.file` 号。
     *
     * @param int $length 初始长度
     * @return string
     */
    public function allocateNumber($length = 7)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz123456789';
        $db = DB::getFacadeRoot();
        for ($len = $length; $len <= $length + 12; $len++) {
            for ($attempt = 0; $attempt < 80; $attempt++) {
                $number = '';
                for ($i = 0; $i < $len; $i++) {
                    $number .= $chars[mt_rand(0, strlen($chars) - 1)];
                }
                $number = $number . '.file';
                if (!$db->table('file')->where('number', $number)->find()) {
                    return $number;
                }
            }
        }

        throw new \RuntimeException('Could not allocate unique file number');
    }

    /**
     * 分配一个不会与现有 dou_file 中文件名碰撞的基名。
     *
     * 命名规则：`{YmdHis}_{rand6}`，例如 `20260601003045_aZmqwT`。
     * 归属信息由 dou_file 表的 `item_id` 列承担；
     * 时间戳前缀方便运维按上传时间排序与排查。
     *
     * @return string
     */
    public function allocateRandomBasename()
    {
        $db = DB::getFacadeRoot();
        $maxAttempts = 50;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $name = date('YmdHis') . '_' . Str::randomByType('letter', 6);
            $exist = $db->table('file')->where('file', 'LIKE', "%$name%")->find();
            if (!$exist) {
                return $name;
            }
        }

        return date('YmdHis') . '_' . Str::randomByType('letter', 8) . '_' . mt_rand(1000, 9999);
    }

    /**
     * @param array $data
     * @return mixed
     */
    public function insert(array $data)
    {
        return DB::table('file')->data($data)->insert();
    }

    /**
     * @param string $number
     * @param array $data
     * @return mixed
     */
    public function updateByNumber($number, array $data)
    {
        return DB::table('file')->where('number', $number)->data($data)->update();
    }

    /**
     * @param string $number
     * @return array|null
     */
    public function findByNumber($number)
    {
        return DB::table('file')->where('number', $number)->find();
    }

    /**
     * @param string $relativePath
     * @return array|null
     */
    public function findByRelativePath($relativePath)
    {
        return DB::table('file')->where('file', $relativePath)->find();
    }

    /**
     * @param string $number
     * @return string|null
     */
    public function getRelativePathByNumber($number)
    {
        return DB::table('file')->where('number', $number)->value('file');
    }

    /**
     * 删除附件：物理文件、缩略图与表记录。
     *
     * @param string $number
     * @return void
     */
    public function deleteByNumber($number)
    {
        if ($number === '' || $number === null) {
            return;
        }
        $db = DB::getFacadeRoot();
        $file = $db->table('file')->where('number', $number)->find();
        if (!is_array($file) || empty($file)) {
            return;
        }
        $rel = isset($file['file']) ? $file['file'] : '';
        if ($rel !== '') {
            @unlink(PathNormalizer::absoluteFromSiteRoot($rel));
            if (!empty($file['thumb_size'])) {
                $parts = explode('.', $rel);
                if (count($parts) >= 2) {
                    $ext = $parts[count($parts) - 1];
                    $base = substr($rel, 0, strlen($rel) - strlen($ext) - 1);
                    $thumbRel = $base . '_thumb.' . $ext;
                    @unlink(PathNormalizer::absoluteFromSiteRoot($thumbRel));
                }
            }
        }
        $db->table('file')->where('number', $number)->delete();

        $module = isset($file['module']) ? $file['module'] : '';
        $itemId = isset($file['item_id']) ? $file['item_id'] : 0;

        if ($db->fieldExist($module, 'id') && $db->fieldExist($module, 'image')) {
            // 仅当被删 number 恰好是当前主图字段引用的那张时，才清空主图
            // 否则删除图库等其它 type 的附件不应连带清掉 image
            $currentImage = $db->table($module)->where('id', intval($itemId))->value('image');
            if (is_string($currentImage) && $currentImage === $number) {
                $db->table($module)->where('id', intval($itemId))->update(array('image' => ''));
            }
        }
    }

    /**
     * 按 number 重命名物理文件主名（保留扩展名），并同步 dou_file.file。
     *
     * @param string $number
     * @param string $newBasename 不含扩展名
     * @return void
     */
    public function renameBasename($number, $newBasename)
    {
        $oldRel = DB::table('file')->where('number', $number)->value('file');
        if (!$oldRel) {
            return;
        }
        $dir = dirname($oldRel);
        $ext = pathinfo($oldRel, PATHINFO_EXTENSION);
        $newRel = $dir . '/' . $newBasename . '.' . $ext;
        $oldAbs = PathNormalizer::absoluteFromSiteRoot($oldRel);
        $newAbs = PathNormalizer::absoluteFromSiteRoot($newRel);
        if (file_exists($oldAbs) && !file_exists($newAbs)) {
            if (rename($oldAbs, $newAbs)) {
                DB::table('file')->where('number', $number)->data(array('file' => $newRel))->update();
            }
        }
    }

    /**
     * 把 number 对应文件挪到新目录（相对站点根，以 / 结尾）。
     *
     * @param string $number
     * @param string $newDir
     * @return bool
     */
    public function moveToDirectory($number, $newDir)
    {
        $oldRel = DB::table('file')->where('number', $number)->value('file');
        if (!$oldRel) {
            return false;
        }
        $filename = basename($oldRel);
        $newRel = $newDir . $filename;
        $oldAbs = PathNormalizer::absoluteFromSiteRoot($oldRel);
        $newAbs = PathNormalizer::absoluteFromSiteRoot($newRel);
        if (!file_exists($oldAbs)) {
            return false;
        }
        $targetDir = dirname($newAbs);
        if (!is_dir($targetDir)) {
            if (!@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                return false;
            }
        }
        if (rename($oldAbs, $newAbs)) {
            DB::table('file')->where('number', $number)->data(array('file' => $newRel))->update();

            return true;
        }

        return false;
    }

    /**
     * 触发 config.file_update_time 时间戳（让上层 url 拼缓存尾失效）。
     *
     * @return void
     */
    public function touchUpdateTime()
    {
        DB::table('config')->where('name', 'file_update_time')->update(array('value' => time()));
    }

    /**
     * 按 draft_token + uploader 把 draft 行批量认领（item_id 落到真业务 id、状态切 owned）。
     *
     * SQL 安全约束：必须同时校验 module + draft_token + uploader 字段 + status='draft' 四条件，
     * 杜绝跨身份越权 claim。
     *
     * @param mixed $module
     * @param string $draftToken
     * @param string $itemId 真实业务主键（按字符串处理，dou_file.item_id 为 VARCHAR）
     * @param string $uploaderType admin / user / work
     * @param int $uploaderId
     * @return array 命中的 number 列表（用于 caller 后续把 main / content 字段写入业务表）
     */
    public function claimByToken($module, $draftToken, $itemId, $uploaderType, $uploaderId)
    {
        if ($draftToken === '' || $uploaderId <= 0) {
            return array();
        }
        $db = DB::getFacadeRoot();
        $rows = $db->table('file')
            ->field('number')
            ->where('module', $module)
            ->where('draft_token', $draftToken)
            ->where('uploader_type', $uploaderType)
            ->where('uploader_id', $uploaderId)
            ->where('status', 'draft')
            ->select();
        if (empty($rows)) {
            return array();
        }
        $db->table('file')
            ->where('module', $module)
            ->where('draft_token', $draftToken)
            ->where('uploader_type', $uploaderType)
            ->where('uploader_id', $uploaderId)
            ->where('status', 'draft')
            ->data(array(
                'item_id' => $itemId,
                'status' => 'owned',
                'draft_token' => '',
                'draft_expire_at' => null,
                'last_used_at' => date('Y-m-d H:i:s'),
            ))
            ->update();

        $numbers = array();
        foreach ((array) $rows as $row) {
            $numbers[] = $row['number'];
        }

        return $numbers;
    }

    /**
     * 懒 GC：清理某 uploader 在某 module 下「draft_expire_at < now」的过期 drafts。
     *
     * 物理文件 + 缩略图 + dou_file 行一并删除，等价于对每条命中调 deleteByNumber。
     * TTL 由 storeDraft 写入的 draft_expire_at 列承载（默认 7 天），本方法仅做到期判定。
     *
     * @param string $uploaderType admin / user / work
     * @param int $uploaderId
     * @param mixed $module
     * @return int 删除的附件数
     */
    public function cleanupUserDrafts($uploaderType, $uploaderId, $module)
    {
        if ($uploaderId <= 0) {
            return 0;
        }
        $db = DB::getFacadeRoot();
        $now = date('Y-m-d H:i:s');
        $rows = $db->table('file')
            ->field('number')
            ->where('uploader_type', $uploaderType)
            ->where('uploader_id', $uploaderId)
            ->where('module', $module)
            ->where('status', 'draft')
            ->where('draft_expire_at', '<', $now)
            ->select();
        $count = 0;
        foreach ((array) $rows as $row) {
            $this->deleteByNumber($row['number']);
            $count++;
        }

        return $count;
    }
}
