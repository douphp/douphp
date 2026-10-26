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

namespace Dou\Front\Service\File;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Attachment\AttachmentRules;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Str;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台通用文件上传服务。
 *
 * 定位：`AttachmentService` 的前台唯一出口，与任何业务模块无关。调用方（form / aftersale /
 * user / share / health / distribution / comment）只关心「传进来模块名、前缀、类型」，
 * 拿回一段列表 HTML 直接 `html()` 到容器里。
 *
 * 与后台组件的差异：
 * - 相册（main / gallery）直接复用 `GalleryQuery` 的 li HTML（listId 参数化为 `{prefix}List`），
 *   与后台同一套渲染，避免两份结构漂移。
 * - 附件仍自渲染：`AttachQuery::renderItemHtml()` 输出的是后台 mask 卡片，前台是文本行 +
 *   `span.del`，结构确实不同。
 * - 归属策略（`$ownedOwnerMap` / `assertOwnership()`）只属于前台会员语义，不得下移 core。
 *
 * 参数归一与策略解析统一走 `AttachmentRules`，与后台共享同一套白名单 / 上限口径。
 *
 * 草稿键：`dou_file.draft_token` 只有 40 字符，且同一页面可能有多个上传字段，故用
 * `md5(基础 token + '_' + prefix)` 派生固定 32 位键，实现字段级隔离。
 */
class FileService extends BaseService
{
    /**
     * 允许前端直接写 owned 的模块 → 归属校验参数。
     *
     * 前端 item_id 由客户端提交，必须验证该业务行归属当前会员；未登记的模块一律拒绝
     * owned 直写（新建场景走草稿，业务保存时再 adopt）。
     *
     *   table - 承载业务主键的表；key - 主键字段；owner - 归属会员字段
     *
     * @var array
     */
    private static $ownedOwnerMap = array(
        'distribution' => array('table' => 'distribution', 'key' => 'user_id', 'owner' => 'user_id'),
        'health' => array('table' => 'health', 'key' => 'id', 'owner' => 'user_id'),
        'aftersale' => array('table' => 'aftersale', 'key' => 'id', 'owner' => 'user_id'),
        'share' => array('table' => 'share', 'key' => 'id', 'owner' => 'user_id'),
        // 评论的 item_id 是订单项主键，归属即订单项买家
        'comment' => array('table' => 'order_item', 'key' => 'id', 'owner' => 'user_id'),
    );

    /**
     * 派生草稿键：同一全局 token 下，不同 prefix（字段）互不可见。
     *
     * 算法已上收 `AttachmentRules`，此处保留静态入口是因为 `front/service/form/FormService.php`
     * 直接调用它做表单草稿认领；删除会中断历史草稿的归属。
     *
     * @param string $draftToken 全局草稿 token
     * @param string $prefix 字段 DOM 前缀
     * @return string 32 位 md5
     */
    public static function draftKey($draftToken, $prefix)
    {
        return AttachmentRules::draftKey($draftToken, $prefix);
    }

    /**
     * 上传并返回整段列表 HTML。
     *
     * @param string $module 落盘模块名
     * @param string $prefix DOM 前缀
     * @param string $type main / gallery / attach
     * @param string $itemId 编辑场景业务主键；空串走草稿
     * @param string $draftToken 页面全局草稿 token
     * @param int $userId 当前会员 ID（必须 > 0）
     * @param array $options img_width / extensions / max_size / watermark
     * @return string
     * @throws DomainException
     */
    public function upload($module, $prefix, $type, $itemId, $draftToken, $userId, array $options = array())
    {
        $module = AttachmentRules::normalizeModule($module);
        $prefix = AttachmentRules::normalizePrefix($prefix);
        $type = AttachmentRules::normalizeType($type);
        $userId = (int) $userId;
        $itemId = AttachmentRules::normalizeItemId($itemId);

        if ($module === '') {
            throw new DomainException(lang('file_module_invalid'));
        }
        if ($prefix === '') {
            throw new DomainException(lang('file_prefix_invalid'));
        }
        if ($type === '') {
            throw new DomainException(lang('file_type_invalid'));
        }
        if ($userId <= 0) {
            throw new DomainException(lang('file_login_required'));
        }

        $useDraft = ($itemId === '' && (string) $draftToken !== '');
        if (!$useDraft && $itemId === '') {
            throw new DomainException(lang('file_draft_invalid'));
        }
        if (!$useDraft) {
            // 编辑场景直写 owned：必须先证明该业务行属于当前会员，否则可向他人记录挂文件
            $this->assertOwnership($module, $itemId, $userId);
        }

        $files = UploadedFile::allFromGlobals($prefix . '_file');
        if (empty($files)) {
            throw new DomainException(lang('file_choose_required'));
        }

        $uploadOptions = $this->buildUploadOptions($type, $prefix, $userId, $options);

        // 单图字段：写入前清空该字段的既有文件，重复上传即替换（gallery / attach 追加）
        if ($type === 'main') {
            $this->purgeExisting(
                $module,
                $useDraft ? self::draftKey($draftToken, $prefix) : '',
                $useDraft ? '' : $itemId,
                $userId
            );
        }

        $errors = array();
        foreach ($files as $file) {
            try {
                if ($useDraft) {
                    attachment()->storeDraft(
                        $module,
                        $file,
                        'user',
                        $userId,
                        self::draftKey($draftToken, $prefix),
                        $type,
                        $uploadOptions
                    );
                } else {
                    attachment()->store($module, $itemId, $file, $type, $uploadOptions->withUploader('user', $userId));
                }
            } catch (DomainException $e) {
                $errors[] = $e->getMessage();
            }
        }

        // 逐件捕获：一张被拒不能中断整批，否则已入库的文件不回显
        if (count($errors) === count($files) && !empty($errors)) {
            throw new DomainException($errors[0]);
        }

        if ($useDraft) {
            return $this->renderList($module, $prefix, $type, '', self::draftKey($draftToken, $prefix), $userId);
        }

        return $this->renderList($module, $prefix, $type, $itemId, '', $userId);
    }

    /**
     * 删除并返回剩余列表 HTML。
     *
     * @param string $number 文件编号
     * @param string $prefix DOM 前缀（用于删除回调）
     * @param int $userId 当前会员 ID
     * @return string
     * @throws DomainException
     */
    public function delete($number, $prefix, $userId)
    {
        $prefix = AttachmentRules::normalizePrefix($prefix);
        $userId = (int) $userId;

        if ($userId <= 0) {
            throw new DomainException(lang('file_login_required'));
        }

        // 文件编号形如 `3eodeko.file`（allocateNumber 末尾固定拼 .file），必须保留点号
        $number = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $number);
        if ($number === '') {
            throw new DomainException(lang('file_not_exist'));
        }

        $row = DB::table('file')
            ->field('number, module, item_id, type, status, draft_token, uploader_type, uploader_id')
            ->where('number', $number)
            ->find();

        if (empty($row)) {
            throw new DomainException(lang('file_not_exist'));
        }
        if ((string) $row['uploader_type'] !== 'user' || (int) $row['uploader_id'] !== $userId) {
            throw new DomainException(lang('file_no_permission'));
        }

        attachment()->delete($number);

        $module = AttachmentRules::normalizeModule($row['module']);
        $type = AttachmentRules::normalizeType($row['type']);
        if ($module === '' || $type === '') {
            return '';
        }

        // 按删除前的行状态回显：draft 用行内已派生的 draft_token，owned 用业务主键
        if ((string) $row['status'] === 'draft' && (string) $row['draft_token'] !== '') {
            return $this->renderList($module, $prefix, $type, '', (string) $row['draft_token'], $userId);
        }

        return $this->renderList($module, $prefix, $type, (string) $row['item_id'], '', $userId);
    }

    /**
     * 渲染列表 HTML（新建场景调用方传 list_html 空串，不做预取）。
     *
     * @param string $module
     * @param string $prefix
     * @param string $type
     * @param string $itemId 编辑场景业务主键
     * @param string $draftKey 已派生的草稿键（不是页面上的基础 token）
     * @param int $userId
     * @return string
     */
    public function renderList($module, $prefix, $type, $itemId, $draftKey = '', $userId = 0)
    {
        $module = AttachmentRules::normalizeModule($module);
        $prefix = AttachmentRules::normalizePrefix($prefix);
        $type = AttachmentRules::normalizeType($type);
        $itemId = AttachmentRules::normalizeItemId($itemId);
        $draftKey = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $draftKey);
        $userId = (int) $userId;

        if ($module === '' || $prefix === '' || $type === '') {
            return '';
        }

        // 相册：直接取 core 的 li HTML，删除目标由 listId 显式指定为前台容器的列表 id
        if ($type !== 'attach') {
            $listId = $prefix . 'List';
            if ($itemId !== '') {
                return (string) attachment()->gallery($module, $itemId, $type, false, $listId);
            }
            if ($draftKey !== '' && $userId > 0) {
                return (string) attachment()->galleryByDraft($module, $draftKey, 'user', $userId, $type, false, $listId);
            }

            return '';
        }

        // 附件：后台是 mask 卡片，前台是文本行，结构不同，仍用数组模式自渲染
        if ($itemId !== '') {
            $rows = attachment()->attachList($module, $itemId, $type, true);
        } elseif ($draftKey !== '' && $userId > 0) {
            $rows = attachment()->attachListByDraft($module, $draftKey, 'user', $userId, $type, true);
        } else {
            return '';
        }

        $html = '';
        foreach ((array) $rows as $row) {
            $html .= $this->renderAttachItem($row, $prefix);
        }

        return $html;
    }

    /**
     * 附件项：文本行 + 删除按钮。
     *
     * 删除不绑内联事件：输出 `data-file-del` / `data-number` / `data-list`，由 `js/file.js`
     * 在 document 上统一委托。span 标签不能改成 button（主题 `.fileBox .fileList li span`
     * 按标签名命中悬停样式）。
     *
     * @param array $row
     * @param string $prefix
     * @return string
     */
    private function renderAttachItem(array $row, $prefix)
    {
        $number = htmlspecialchars((string) $row['number'], ENT_QUOTES, 'UTF-8');
        $href = htmlspecialchars(isset($row['file']) ? (string) $row['file'] : '', ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars(isset($row['name']) ? (string) $row['name'] : '', ENT_QUOTES, 'UTF-8');
        $size = htmlspecialchars(isset($row['size_format']) ? (string) $row['size_format'] : '', ENT_QUOTES, 'UTF-8');
        $icon = isset($row['icon']) ? preg_replace('/[^a-z0-9\-]/i', '', (string) $row['icon']) : 'bi-file-earmark';
        $listId = htmlspecialchars($prefix . 'List', ENT_QUOTES, 'UTF-8');

        return '<li class="file-item">'
            . '<i class="bi ' . $icon . ' file-attachment-icon" aria-hidden="true"></i>'
            . '<a class="file-attachment-name" href="' . $href . '" target="_blank" rel="noopener">' . $name . '</a>'
            . '<em class="file-attachment-size">' . $size . '</em>'
            . '<span class="del" data-file-del data-number="' . $number . '" data-list="' . $listId . '">X</span>'
            . '</li>';
    }

    /**
     * 清空同一字段的既有单图。
     *
     * 附件模型没有「字段」维度，`type=main` 只能按草稿键或业务主键定位；草稿键已含
     * prefix，编辑场景则依赖调用方保证「同一业务主键只挂一个单图字段」。
     *
     * @param string $module
     * @param string $draftKey 已派生的草稿键（草稿场景）
     * @param string $itemId 业务主键（编辑场景）
     * @param int $userId
     * @return void
     */
    private function purgeExisting($module, $draftKey, $itemId, $userId)
    {
        $query = DB::table('file')
            ->field('number')
            ->where('module', $module)
            ->where('type', 'main')
            ->where('uploader_type', 'user')
            ->where('uploader_id', $userId);

        if ($draftKey !== '') {
            $query = $query->where('draft_token', $draftKey)->where('status', 'draft');
        } else {
            $query = $query->where('item_id', $itemId)->where('status', 'owned');
        }

        foreach ((array) $query->select() as $row) {
            if (!empty($row['number'])) {
                attachment()->delete((string) $row['number']);
            }
        }
    }

    /**
     * 构造上传选项：白名单取「请求值 ∩ 平台基线」，避免字段配置放开危险扩展名。
     *
     * @param string $type
     * @param string $prefix
     * @param int $userId
     * @param array $options
     * @return AttachmentUploadOptions
     */
    private function buildUploadOptions($type, $prefix, $userId, array $options)
    {
        $opts = AttachmentUploadOptions::create()
            ->withBasename($prefix . '_' . Str::randomByType('number', 6))
            ->withBusinessField($prefix . '_file')
            ->withUploader('user', $userId);

        $extensions = AttachmentRules::resolveExtensions($type, isset($options['extensions']) ? (string) $options['extensions'] : '');
        if ($extensions !== '') {
            $opts = $opts->withAllowExtensions($extensions);
        }

        // 体积上限：请求值只能收紧平台基线（与扩展名同一策略），不能自行放宽
        $maxSize = isset($options['max_size']) ? (int) $options['max_size'] : 0;
        $opts = $opts->withUploadMaxKb(AttachmentRules::resolveMaxKb($type, $maxSize));

        if ($type === 'attach') {
            // 附件独占 images/{module}/attach/，不做缩放与水印
            return $opts->withDirectory('attach');
        }

        $imgWidth = isset($options['img_width']) ? (int) $options['img_width'] : (int) Config::get('site.image_width', 0);
        if ($imgWidth > 0) {
            $opts = $opts->withImageWidth($imgWidth);
        }

        $watermark = Config::get('site.watermark', '');
        if ($watermark) {
            $opts = $opts->withWatermark($watermark);
        }

        return $opts;
    }

    /**
     * 校验 owned 业务主键是否归属当前会员。
     *
     * 前台 FileService 与 API user/filebox 共用；未登记模块不允许 owned 直写。
     * product 属工作台数据，按 WorkService 的 product 操作权限判断。
     *
     * @param string $module
     * @param string $itemId
     * @param int $userId
     * @return void
     * @throws DomainException
     */
    public function assertOwnedItem($module, $itemId, $userId)
    {
        $module = AttachmentRules::normalizeModule($module);
        $itemId = AttachmentRules::normalizeItemId($itemId);
        $userId = (int) $userId;

        if ($module === '' || $itemId === '' || $userId <= 0) {
            throw new DomainException(lang('file_no_permission'));
        }

        if ($module === 'product') {
            if (!Config::get('features.work', false)) {
                throw new DomainException(lang('file_no_permission'));
            }
            $workService = new \Dou\Core\Service\Work\WorkService();
            if (!$workService->checkPermission('product', $userId)) {
                throw new DomainException(lang('file_no_permission'));
            }
            return;
        }

        $this->assertOwnership($module, $itemId, $userId);
    }

    /**
     * 校验 owned 直写的业务主键归属当前会员。
     *
     * @param string $module
     * @param string $itemId
     * @param int $userId
     * @return void
     * @throws DomainException
     */
    private function assertOwnership($module, $itemId, $userId)
    {
        if (!isset(self::$ownedOwnerMap[$module])) {
            throw new DomainException(lang('file_no_permission'));
        }

        $map = self::$ownedOwnerMap[$module];
        if (!DB::tableExist($map['table'])) {
            throw new DomainException(lang('file_no_permission'));
        }
        $owner = DB::table($map['table'])->where($map['key'], $itemId)->value($map['owner']);
        if ((int) $owner !== (int) $userId) {
            throw new DomainException(lang('file_no_permission'));
        }
    }
}
