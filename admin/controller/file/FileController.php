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

namespace Dou\Admin\Controller\File;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Service\Theme\ThemeSettingsReader;
use Dou\Core\Facade\DB;
use Dou\Core\Facade\Session;
use Dou\Core\Filesystem\Storage;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\Attachment\AttachmentRules;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\Attachment\LanguagePath;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台附件上传 / 删除（编辑器与缩略图）
 *
 * 磁盘路径依赖运行时模块名（如 images/{module}/），须在各自动作内解析，不宜在构造函数缓存单一 Disk。
 */
class FileController extends BaseController
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function box(Request $request)
    {
        $module = $request->alpha('module');
        Storage::build('images/' . $module . '/');
        $languagePack = LanguagePath::normalize((string) $request->post('language_pack', ''));

        $item_id = $request->integer('item_id', 0);
        // 前端组件名统一用 attachment；存储/查询层沿用历史 type=attach
        $type = AttachmentRules::normalizeTypeForAdmin($request->alpha('type'));
        $target = $request->basicString('target');
        // img_width 未传时的兜底按类型区分：编辑器插图(content)用 editor_image_width，
        // 其余（相册 gallery 等主图类）用 image_width，避免两处配置混淆
        $img_width_fallback = ($type == 'content') ? (int) Config::get('site.editor_image_width', 0) : (int) Config::get('site.image_width', 1000);
        // 主题配置的主图尺寸优先于全局缩图限宽：避免服务端（只缩不放）把客户端裁剪产物二次压缩
        if ($type != 'content') {
            $themeWidth = (int) app(ThemeSettingsReader::class)->imageWidthForModule($module);
            if ($themeWidth > $img_width_fallback) {
                $img_width_fallback = $themeWidth;
            }
        }
        $img_width = $request->digits('img_width', $img_width_fallback);
        $field = $target . '_file';

        // 新建场景由表单 draft_token 驱动 → 走 storeDraft；编辑场景（已有真实 item_id）走 store 写 owned 行。
        $draft_token = (string) $request->post('draft_token', '');
        $admin_id = (int) auth('admin')->id();
        $useDraft = ($draft_token !== '' && $item_id <= 0);

        $html = '';
        $_NEW_FILES = array();

        if ($type == 'content') {
            $total = count($_FILES[$field]['name']);
            for ($i = 0; $i < $total; $i++) {
                $_NEW_FILES[$field . $i] = array(
                    'name' => $_FILES[$field]['name'][$i],
                    'type' => $_FILES[$field]['type'][$i],
                    'tmp_name' => $_FILES[$field]['tmp_name'][$i],
                    'error' => $_FILES[$field]['error'][$i],
                    'size' => $_FILES[$field]['size'][$i],
                );
            }

            foreach ($_NEW_FILES as $fkey => $value) {
                $opts = AttachmentUploadOptions::create()
                    ->withImageWidth($img_width)
                    ->withWatermark(Config::get('site.watermark', false))
                    ->withBusinessField($fkey)
                    ->withLanguagePack($languagePack)
                    ->withUploader('admin', $admin_id);

                if ($useDraft) {
                    $image = attachment()->storeDraft($module, UploadedFile::fromGlobals($fkey, $_NEW_FILES), 'admin', $admin_id, $draft_token, $type, $opts);
                } else {
                    $image = attachment()->store($module, $item_id, UploadedFile::fromGlobals($fkey, $_NEW_FILES), $type, $opts);
                }

                $html .= '<img src="' . attachment()->url($image) . '" data-file="' . $image . '" /><br/><br/>';
            }
        } else {
            if ($type === 'attach') {
                // 通用附件：不做图片缩放/水印，独占 images/{module}/attach/ 目录；
                // 白名单与上限由 AttachmentRules 统一提供，与前台同一套口径
                $opts = AttachmentUploadOptions::create()
                    ->withDirectory('attach')
                    ->withAllowExtensions(AttachmentRules::baselineExtensions('attach'))
                    ->withUploadMaxKb(AttachmentRules::platformMaxKb('attach'))
                    ->withBusinessField($field)
                    ->withLanguagePack($languagePack)
                    ->withUploader('admin', $admin_id);
            } else {
                $opts = AttachmentUploadOptions::create()
                    ->withImageWidth($img_width)
                    ->withWatermark(Config::get('site.watermark', false))
                    ->withBusinessField($field)
                    ->withLanguagePack($languagePack)
                    ->withUploader('admin', $admin_id);
            }

            // 前端 input 带 multiple：展开多文件数组逐张入库（旧逻辑只取首张导致其余静默丢弃）
            $files = UploadedFile::allFromGlobals($field);
            $uploadErrors = array();
            foreach ($files as $uploaded) {
                if ($type === 'attach') {
                    // 附件多选为主路径：单个文件被拒（扩展名/大小/目录）时不能中断整批，
                    // 否则已入库的文件不回显（新建页还会因重新生成 draft_token 而丢失入口），故逐件捕获。
                    try {
                        if ($useDraft) {
                            attachment()->storeDraft($module, $uploaded, 'admin', $admin_id, $draft_token, $type, $opts);
                        } else {
                            attachment()->store($module, $item_id, $uploaded, $type, $opts);
                        }
                    } catch (\Exception $e) {
                        $uploadErrors[] = $e->getMessage();
                    } catch (\Throwable $e) {
                        $uploadErrors[] = $e->getMessage();
                    }
                } elseif ($useDraft) {
                    attachment()->storeDraft($module, $uploaded, 'admin', $admin_id, $draft_token, $type, $opts);
                } else {
                    attachment()->store($module, $item_id, $uploaded, $type, $opts);
                }
            }

            $draftCtx = $useDraft
                ? array('token' => $draft_token, 'uploader_type' => 'admin', 'uploader_id' => $admin_id)
                : null;
            $html = $this->renderFileListHtml($module, $item_id, $type, $draftCtx);
            if (!empty($uploadErrors)) {
                $html = $this->renderUploadErrors($uploadErrors) . $html;
            }
        }

        return $this->response($html);
    }

    /**
     * 图片编辑（上传后裁剪）：前端裁剪产物按附件号原路径替换原图，路径不变。
     *
     * @param Request $request
     * @return \Dou\Core\Web\Http\Response
     */
    public function crop(Request $request)
    {
        $numberRaw = (string) $request->input('number', '');
        $number = preg_match("/^[a-z0-9.]+$/", $numberRaw) ? $numberRaw : '';
        if ($number === '') {
            return $this->json(array('error' => attachment()->formatFileWrong()));
        }

        // 裁剪产物尺寸由裁剪弹窗输入框唯一决定：以产物自身宽度作为限宽，
        // 在「只缩不放」策略下成为 no-op，避免 site.image_width 把精确尺寸二次压缩
        $file = UploadedFile::fromGlobals('file');
        $cropWidth = (int) Config::get('site.image_width', 1000);
        if ($file instanceof UploadedFile && $file->isValid()) {
            $info = @getimagesize($file->getRealPath());
            if (is_array($info) && (int) $info[0] > $cropWidth) {
                $cropWidth = (int) $info[0];
            }
        }

        // 后处理口径与 box 一致：重打水印
        $opts = AttachmentUploadOptions::create()
            ->withImageWidth($cropWidth)
            ->withWatermark(Config::get('site.watermark', false))
            ->withUploader('admin', (int) auth('admin')->id());
        if (!($file instanceof UploadedFile) || !$file->isValid()) {
            if ($file instanceof UploadedFile && $file->isSizeLimitError()) {
                return $this->json(array('error' => UploadedFile::formatFileOutSize(UploadedFile::phpUploadMaxKb())));
            }

            return $this->json(array('error' => attachment()->formatFileWrong()));
        }

        $path = attachment()->replaceByNumber($number, $file, $opts);
        if ($path === '') {
            return $this->json(array('error' => attachment()->formatFileWrong()));
        }

        return $this->json(array('url' => ROOT_URL . $path . '?v=' . time()));
    }

    /**
     * 记住「上传时裁剪」勾选（缩略图 / 相册 / 站点设置各自一份）。
     *
     * @param Request $request
     * @return \Dou\Core\Web\Http\Response
     */
    public function cropPref(Request $request)
    {
        $on = $request->integer('crop', 0) ? 1 : 0;
        $scope = $request->alpha('scope', 'thumb');
        if (!in_array($scope, array('thumb', 'gallery', 'setting'), true)) {
            $scope = 'thumb';
        }
        Session::set($scope . '_crop', $on);

        return $this->json(array('crop' => $on, 'scope' => $scope));
    }

    /**
     * @param Request $request
     * @return \Dou\Core\Web\Http\Response
     */
    public function destroy(Request $request)
    {
        $numberRaw = (string) $request->input('number', '');
        $number = preg_match("/^[a-z0-9.]+$/", $numberRaw) ? $numberRaw : '';
        // 删除前取全量上下文：删除后行已不存在，draft 重查依赖这些字段
        $file_info = DB::table('file')
            ->field('module, item_id, type, status, draft_token, uploader_type, uploader_id')
            ->where('number', $number)
            ->find();

        if (empty($file_info) || !isset($file_info['module'])) {
            // 不能回空串：空串在客户端等价于「列表已空」，重复点击 / 多标签并发删除会让
            // 已渲染的文件从界面上被误清空（删一条丢满屏），因此回 422 + 文案走提示分支
            return $this->json(array('code' => 1, 'message' => lang('file_not_exist')), 422);
        }

        attachment()->delete($number);

        // draft 行（新建页未保存）改按 token + 上传者重查；owned 行按 item_id 重查。
        // 旧逻辑固定走 gallery 的 owned 查询，导致新建页删除草稿图片后列表被清空。
        $draftCtx = null;
        if (isset($file_info['status']) && $file_info['status'] === 'draft'
            && isset($file_info['draft_token']) && (string) $file_info['draft_token'] !== ''
            && isset($file_info['uploader_id']) && (int) $file_info['uploader_id'] > 0) {
            $draftCtx = array(
                'token' => (string) $file_info['draft_token'],
                'uploader_type' => isset($file_info['uploader_type']) ? (string) $file_info['uploader_type'] : '',
                'uploader_id' => (int) $file_info['uploader_id'],
            );
            // 仅回显删除者自己的草稿列表：否则删到他人 draft 行时会回传他人 token 名下的附件信息
            if ($draftCtx['uploader_type'] !== 'admin' || $draftCtx['uploader_id'] !== (int) auth('admin')->id()) {
                $draftCtx = null;
            }
        }

        $html = $this->renderFileListHtml(
            $file_info['module'],
            (string) $file_info['item_id'],
            (string) $file_info['type'],
            $draftCtx
        );

        return $this->response($html);
    }

    /**
     * 上传失败提示行：作为列表首行插入（只对 attach 使用，view 不命中 .file-item 以免触发「已更新」徽标）。
     *
     * @param array $messages
     * @return string
     */
    private function renderUploadErrors(array $messages)
    {
        $html = '';
        foreach ($messages as $message) {
            $html .= '<li class="file-item-error">'
                . htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8')
                . '</li>';
        }

        return $html;
    }

    /**
     * 统一输出 fileUpload 列表 HTML：attach 走附件渲染，其余走相册渲染。
     *
     * draft 上下文（新建页未保存）按 token + 上传者重查，只暴露当前管理员自己上传的部分。
     *
     * @param string $module
     * @param mixed $itemId
     * @param string $type
     * @param array|null $draftCtx array('token' => string, 'uploader_type' => string, 'uploader_id' => int)
     * @return string
     */
    private function renderFileListHtml($module, $itemId, $type, $draftCtx = null)
    {
        $isAttach = ($type === 'attach');

        if (is_array($draftCtx)
            && isset($draftCtx['token']) && (string) $draftCtx['token'] !== ''
            && isset($draftCtx['uploader_id']) && (int) $draftCtx['uploader_id'] > 0) {
            $kind = isset($draftCtx['uploader_type']) ? (string) $draftCtx['uploader_type'] : '';
            if (!in_array($kind, array('admin', 'user', 'work'), true)) {
                $kind = 'admin';
            }
            $uploaderId = (int) $draftCtx['uploader_id'];

            return $isAttach
                ? attachment()->attachListByDraft($module, (string) $draftCtx['token'], $kind, $uploaderId, $type)
                : attachment()->galleryByDraft($module, (string) $draftCtx['token'], $kind, $uploaderId, $type);
        }

        return $isAttach
            ? attachment()->attachList($module, $itemId, $type)
            : attachment()->gallery($module, $itemId, $type);
    }

    /**
     * @param Request $request
     * @return \Dou\Core\Web\Http\Response
     */
    public function bigfile(Request $request)
    {
        $module = $request->alpha('module');
        $disk = Storage::build('images/' . $module . '/');

        $file_type = 'zip,rar,pdf,xls,xlsx,doc,docx,wmv,avi,mp4,flv,mp3';

        if ($request->input('act') == 'ext') {
            $check_fn = $request->input('check_filename', '');
            $name = explode('.', $check_fn);
            $img_count = count($name);
            $img_type = $name[$img_count - 1];
            if (stripos($file_type, $img_type) === false) {
                return $this->response(lang('file_support') . $file_type . lang('file_support_no') . $img_type);
            }

            return $this->response('');
        }

        $item_id = (int) $request->integer('item_id', 0);
        $draft_token = (string) $request->query('draft_token', '');
        if ($item_id <= 0 && $draft_token === '') {
            return $this->response('');
        }
        $type = $request->alpha('type');
        // 与 box() 保持一致：前端组件名 attachment 归一为历史存储值 attach
        if ($type === 'attachment') {
            $type = 'attach';
        }
        if ($type === '') {
            return $this->response('');
        }
        $target = $request->basicString('target');
        if ($target === '') {
            return $this->response('');
        }
        $file_md5_value = (string) $request->post('file_md5_value', '');
        if (!preg_match("/^[A-Za-z0-9]+$/", $file_md5_value)) {
            return $this->response('');
        }
        $file_field = 'file';

        $custom_filename = date('YmdHis') . '_' . substr($file_md5_value, 0, 6);
        $uploaded = $request->file($file_field);
        if (is_array($uploaded)) {
            $uploaded = isset($uploaded[0]) ? $uploaded[0] : null;
        }
        $chunkInput = array(
            'blob_num' => $request->post('blob_num', ''),
            'total_blob_num' => $request->post('total_blob_num', ''),
            'file_name' => $request->post('file_name', ''),
            'sql_link_url' => $request->post('sql_link_url', ''),
            'file' => $uploaded,
        );
        $useDraft = ($draft_token !== '' && $item_id <= 0);
        if ($useDraft) {
            $admin_id = (int) auth('admin')->id();
            if ($admin_id <= 0) {
                return $this->response('');
            }
            $data = attachment()->chunkedStoreDraft($module, 'admin', $admin_id, $draft_token, $file_field, $type, $custom_filename, $file_type, null, $chunkInput);
        } else {
            $data = attachment()->chunkedStore($module, $item_id, $file_field, $type, $custom_filename, $file_type, null, 'admin', (int) auth('admin')->id(), $chunkInput);
        }

        return $this->json($data);
    }
}
