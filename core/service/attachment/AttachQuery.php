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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * `dou_file` 表的附件（type=attach）查询与列表渲染。
 *
 * 与 {@see GalleryQuery} 平行：按条目 / draft 两个维度列表，支持后台 li HTML
 * 与前台数组两种输出。后台 li 沿用 fileUpload 约定（data-number + file-action-remove），
 * 删除按钮由 common.js 的文档级事件委托接管，列表随 admin.file.destroy 回传 HTML 刷新。
 */
class AttachQuery
{
    /**
     * 列出某模块 / 某条目 / 某类型的全部附件（owned）。
     *
     * @param mixed $module
     * @param mixed $itemId
     * @param mixed $type
     * @param bool $arrayMode 是否返回数组形态（false → 返回后台 li 字符串）
     * @return mixed
     */
    public function listByItem($module, $itemId, $type, $arrayMode = false)
    {
        $rows = DB::table('file')
            ->field('number, file, original_name, size')
            ->where('module', $module)
            ->where('item_id', $itemId)
            ->where('type', $type)
            ->where('status', 'owned')
            ->order('id ASC')
            ->select();

        return $this->buildList($rows, $itemId, $arrayMode);
    }

    /**
     * 列出某 module + 某 draft_token + 某上传者 + 某 type 下的全部 draft 附件。
     *
     * 用于新建场景（item_id=0）的表单附件列表：只展示当前上传者在当前 token 下
     * 上传的部分，避免越权可见。
     *
     * @param string $module
     * @param string $draftToken
     * @param string $uploaderType admin / user / work
     * @param int $uploaderId
     * @param string $type
     * @param bool $arrayMode 是否返回数组形态（false → 返回后台 li 字符串）
     * @return mixed
     */
    public function listByDraft($module, $draftToken, $uploaderType, $uploaderId, $type, $arrayMode = false)
    {
        $data = $arrayMode ? array() : '';
        if ($draftToken === '' || (int) $uploaderId <= 0) {
            return $data;
        }
        $rows = DB::table('file')
            ->field('number, file, original_name, size')
            ->where('module', $module)
            ->where('status', 'draft')
            ->where('draft_token', $draftToken)
            ->where('uploader_type', $uploaderType)
            ->where('uploader_id', (int) $uploaderId)
            ->where('type', $type)
            ->order('id ASC')
            ->select();

        return $this->buildList($rows, 0, $arrayMode);
    }

    /**
     * @param array $rows
     * @param mixed $itemId
     * @param bool $arrayMode
     * @return mixed
     */
    private function buildList($rows, $itemId, $arrayMode)
    {
        $data = $arrayMode ? array() : '';
        foreach ((array) $rows as $row) {
            if ($arrayMode) {
                $data[] = $this->formatArrayItem($row, $itemId);
            } else {
                $data .= $this->renderItemHtml($row);
            }
        }

        return $data;
    }

    /**
     * 数组形态（前台消费）：number / item_id / file(URL) / name / size / size_format / ext / icon。
     *
     * @param array $row
     * @param mixed $itemId
     * @return array
     */
    private function formatArrayItem($row, $itemId)
    {
        $size = isset($row['size']) ? (int) $row['size'] : 0;

        return array(
            'number' => $row['number'],
            'item_id' => $itemId,
            'file' => ROOT_URL . $row['file'],
            'name' => $this->displayName($row),
            'size' => $size,
            'size_format' => $this->formatSize($size),
            'ext' => strtolower(pathinfo((string) $row['file'], PATHINFO_EXTENSION)),
            'icon' => $this->iconClass((string) $row['file']),
        );
    }

    /**
     * 后台附件行：图标 + 原始文件名 + 大小 + 删除按钮。
     *
     * 删除按钮 class 与相册保持一致，并带上 `data-file-del` / `data-number`：
     * 由后台 `douFileUploader` 在 document 上统一委托，不再依赖内联 onclick。
     *
     * @param array $row
     * @return string
     */
    private function renderItemHtml($row)
    {
        $number = htmlspecialchars((string) $row['number'], ENT_QUOTES, 'UTF-8');
        $src = htmlspecialchars(ROOT_URL . $row['file'], ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars($this->displayName($row), ENT_QUOTES, 'UTF-8');
        $size = htmlspecialchars($this->formatSize(isset($row['size']) ? (int) $row['size'] : 0), ENT_QUOTES, 'UTF-8');
        $icon = $this->iconClass((string) $row['file']);
        $delTitle = htmlspecialchars(lang('del'), ENT_QUOTES, 'UTF-8');

        return '<li class="file-item" data-number="' . $number . '">'
            . '<i class="bi ' . $icon . ' file-attachment-icon" aria-hidden="true"></i>'
            . '<a class="file-attachment-name" href="' . $src . '" title="' . $name . '" target="_blank" rel="noopener">' . $name . '</a>'
            . '<em class="file-attachment-size">' . $size . '</em>'
            . '<button type="button" class="file-action file-action-remove bi-x" data-file-del data-number="' . $number . '" title="' . $delTitle . '"></button>'
            . '</li>';
    }

    /**
     * 展示名：优先 original_name（本次起由 store/storeDraft 写入），兜底磁盘文件名。
     *
     * @param array $row
     * @return string
     */
    private function displayName($row)
    {
        $original = isset($row['original_name']) ? trim((string) $row['original_name']) : '';
        if ($original !== '') {
            return $original;
        }

        return basename((string) $row['file']);
    }

    /**
     * 扩展名 → bootstrap-icons 图标（未知类型回退通用文本图标）。
     *
     * @param string $file
     * @return string
     */
    private function iconClass($file)
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            return 'bi-file-earmark-pdf';
        }
        if (in_array($ext, array('doc', 'docx'), true)) {
            return 'bi-file-earmark-word';
        }
        if (in_array($ext, array('xls', 'xlsx'), true)) {
            return 'bi-file-earmark-excel';
        }
        if (in_array($ext, array('ppt', 'pptx'), true)) {
            return 'bi-file-earmark-ppt';
        }
        if (in_array($ext, array('zip', 'rar', '7z'), true)) {
            return 'bi-file-earmark-zip';
        }
        if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true)) {
            return 'bi-file-earmark-image';
        }

        return 'bi-file-earmark-text';
    }

    /**
     * 可读文件大小：<1MB 显示 KB，否则显示 MB。
     *
     * @param int $bytes
     * @return string
     */
    private function formatSize($bytes)
    {
        $bytes = (int) $bytes;
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / (1024 * 1024), 2) . ' MB';
    }
}
