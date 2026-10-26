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
 * `dou_file` 表的画廊查询。
 *
 * 三个语义：列表、单条首图、按 ids 批量首图映射。
 */
class GalleryQuery
{
    /**
     * 进程内首图热缓存：module => (itemId => url)。
     *
     * 由 galleryFirstMap() 批量写入、galleryFirst() 命中，消除「列表 prefetch 已批量取过、
     * 详情/再读又逐条查」的 N+1。风格同 {@see \Dou\Core\Facade\Url::warmupUrlCache}。
     *
     * @var array<string, array<int|string, string>>
     */
    private $cache = array();

    /**
     * 列出某模块 / 某条目 / 某类型的全部附件。
     *
     * @param mixed $module
     * @param mixed $itemId
     * @param mixed $type
     * @param bool $arrayMode 是否返回数组形态（false → 返回 li 字符串）
     * @param string|null $listId 删除回调指向的列表 id，null → `{type}List`
     * @return mixed
     */
    public function galleryList($module, $itemId, $type, $arrayMode = false, $listId = null)
    {
        $data = $arrayMode ? array() : '';
        $rows = DB::table('file')
            ->field('number, file')
            ->where('module', $module)
            ->where('item_id', $itemId)
            ->where('type', $type)
            ->where('status', 'owned')
            ->order('id ASC')
            ->select();
        foreach ((array) $rows as $row) {
            if ($arrayMode) {
                $data[] = array(
                    'number' => $row['number'],
                    'item_id' => $itemId,
                    'file' => ROOT_URL . $row['file'],
                );
            } else {
                $data .= $this->renderItemHtml($row, $type, $listId);
            }
        }

        return $data;
    }

    /**
     * 列出某 module + 某 draft_token + 某上传者 + 某 type 下的全部 draft 附件。
     *
     * 用于「新建场景」编辑器右侧 gallery 缩略图列表：附件仍处于 draft（item_id=0），
     * 但只展示当前 admin/user/work 自己在当前 draft_token 下上传的那部分，避免越权可见。
     *
     * @param string $module
     * @param string $draftToken
     * @param string $uploaderType admin / user / work
     * @param int $uploaderId
     * @param string $type gallery / main / content
     * @param bool $arrayMode 是否返回数组形态（false → 返回 li 字符串）
     * @param string|null $listId 删除回调指向的列表 id，null → `{type}List`
     * @return mixed
     */
    public function galleryListByDraft($module, $draftToken, $uploaderType, $uploaderId, $type, $arrayMode = false, $listId = null)
    {
        $data = $arrayMode ? array() : '';
        if ($draftToken === '' || $uploaderId <= 0) {
            return $data;
        }
        $rows = DB::table('file')
            ->field('number, file')
            ->where('module', $module)
            ->where('status', 'draft')
            ->where('draft_token', $draftToken)
            ->where('uploader_type', $uploaderType)
            ->where('uploader_id', (int) $uploaderId)
            ->where('type', $type)
            ->order('id ASC')
            ->select();
        foreach ((array) $rows as $row) {
            if ($arrayMode) {
                $data[] = array(
                    'number' => $row['number'],
                    'item_id' => 0,
                    'file' => ROOT_URL . $row['file'],
                );
            } else {
                $data .= $this->renderItemHtml($row, $type, $listId);
            }
        }

        return $data;
    }

    /**
     * 相册列表项：后台悬停 mask（替换 / 裁剪 / 删除）+ 前台 span.del 删除。
     *
     * mask 默认 inline display:none，仅后台 CSS 在悬停时覆盖为 flex，避免前台未引入
     * admin 样式时三个按钮裸露。
     *
     * 删除按钮不绑定任何内联事件：输出 `data-file-del` + `data-number` + `data-list`，
     * 由前后台各自的脚本在 document 上一次性委托（前台 douFileBox / 后台 douFileUploader）。
     * `data-list` 只是「这个删除按钮属于哪个列表」的自描述信息；脚本按容器
     * `data-file-prefix` 派生 `#{prefix}List` 定位刷新目标，不读该属性，故默认值
     * `{type}List` 与实际 prefix 不同也不影响功能。同一行的两个删除入口必须输出一致的属性集。
     *
     * span 而非 button：后台 `.file-upload .file-list li > span.del` 与前台
     * `.fileBox .fileList li span` 两条样式规则都按标签名命中，换标签会丢样式。
     *
     * @param array $row
     * @param mixed $type
     * @param string|null $listId null → `{type}List`
     * @return string
     */
    private function renderItemHtml($row, $type, $listId = null)
    {
        $number = htmlspecialchars((string) $row['number'], ENT_QUOTES, 'UTF-8');
        $src = htmlspecialchars(ROOT_URL . $row['file'], ENT_QUOTES, 'UTF-8');
        $ext = strtolower(pathinfo((string) $row['file'], PATHINFO_EXTENSION));
        $croppable = in_array($ext, array('jpg', 'jpeg', 'png', 'webp'), true);
        $class = $croppable ? 'file-item file-item-croppable' : 'file-item';
        $listId = $listId === null || $listId === '' ? (string) $type . 'List' : (string) $listId;
        $listId = htmlspecialchars($listId, ENT_QUOTES, 'UTF-8');
        $replaceTitle = htmlspecialchars(lang('replace'), ENT_QUOTES, 'UTF-8');
        $cropTitle = htmlspecialchars(lang('crop_title'), ENT_QUOTES, 'UTF-8');
        $delTitle = htmlspecialchars(lang('del'), ENT_QUOTES, 'UTF-8');

        return '<li class="' . $class . '" data-number="' . $number . '">'
            . '<img src="' . $src . '" alt="" />'
            . '<div class="file-item-mask" style="display:none">'
            . '<button type="button" class="file-action file-action-replace bi-arrow-repeat" title="' . $replaceTitle . '"></button>'
            . '<button type="button" class="file-action file-action-crop bi-scissors" title="' . $cropTitle . '"></button>'
            . '<button type="button" class="file-action file-action-remove bi-x" data-file-del data-number="' . $number . '" data-list="' . $listId . '" title="' . $delTitle . '"></button>'
            . '</div>'
            . '<span class="del" data-file-del data-number="' . $number . '" data-list="' . $listId . '">X</span>'
            . '</li>';
    }

    /**
     * 取某条目下首张「gallery」类型附件 URL。
     *
     * @param mixed $module
     * @param mixed $itemId
     * @return string
     */
    public function galleryFirst($module, $itemId)
    {
        if (isset($this->cache[$module]) && array_key_exists($itemId, $this->cache[$module])) {
            return $this->cache[$module][$itemId];
        }

        $file = DB::table('file')
            ->where('module', $module)
            ->where('item_id', $itemId)
            ->where('type', 'gallery')
            ->where('status', 'owned')
            ->order('id ASC')
            ->value('file');

        $url = $file ? ROOT_URL . $file : '';
        $this->cache[$module][$itemId] = $url;

        return $url;
    }

    /**
     * 批量：按多个 itemId 一次取首图映射。
     *
     * @param mixed $module
     * @param mixed $ids
     * @return array
     */
    public function galleryFirstMap($module, $ids)
    {
        $map = array();
        if (empty($ids)) {
            return $map;
        }
        $rows = DB::table('file')
            ->field('item_id, file')
            ->where('module', $module)
            ->where('type', 'gallery')
            ->where('item_id', 'IN', $ids)
            ->where('status', 'owned')
            ->order('id ASC')
            ->select();
        foreach ((array) $rows as $row) {
            if (!isset($map[$row['item_id']])) {
                $map[$row['item_id']] = ROOT_URL . $row['file'];
            }
        }

        if (!isset($this->cache[$module])) {
            $this->cache[$module] = array();
        }
        foreach ($ids as $id) {
            $this->cache[$module][$id] = isset($map[$id]) ? $map[$id] : '';
        }

        return $map;
    }
}
