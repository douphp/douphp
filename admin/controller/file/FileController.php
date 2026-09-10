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
use Dou\Core\Facade\DB;
use Dou\Core\Facade\Session;
use Dou\Core\Filesystem\Storage;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
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

        $item_id = $request->integer('item_id', 0);
        $type = $request->alpha('type');
        $target = $request->basicString('target');
        // img_width 未传时的兜底按类型区分：编辑器插图(content)用 editor_image_width，
        // 其余（相册 gallery 等主图类）用 image_width，避免两处配置混淆
        $img_width_fallback = ($type == 'content') ? (int) Config::get('site.editor_image_width', 0) : (int) Config::get('site.image_width', 1000);
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
                    ->withUploader('admin', $admin_id);

                if ($useDraft) {
                    $image = attachment()->storeDraft($module, UploadedFile::fromGlobals($fkey, $_NEW_FILES), 'admin', $admin_id, $draft_token, $type, $opts);
                } else {
                    $image = attachment()->store($module, $item_id, UploadedFile::fromGlobals($fkey, $_NEW_FILES), $type, $opts);
                }

                $html .= '<img src="' . attachment()->url($image) . '" data-file="' . $image . '" /><br/><br/>';
            }
        } else {
            $opts = AttachmentUploadOptions::create()
                ->withImageWidth($img_width)
                ->withWatermark(Config::get('site.watermark', false))
                ->withBusinessField($field)
                ->withUploader('admin', $admin_id);

            // 前端 input 带 multiple：展开多文件数组逐张入库（旧逻辑只取首张导致其余静默丢弃）
            $files = UploadedFile::allFromGlobals($field);
            foreach ($files as $uploaded) {
                if ($useDraft) {
                    attachment()->storeDraft($module, $uploaded, 'admin', $admin_id, $draft_token, $type, $opts);
                } else {
                    attachment()->store($module, $item_id, $uploaded, $type, $opts);
                }
            }

            if ($useDraft) {
                $html = attachment()->galleryByDraft($module, $draft_token, 'admin', $admin_id, $type);
            } else {
                $html = attachment()->gallery($module, $item_id, $type);
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
        $number = preg_match("/^[a-z0-9.]+$/", $request->input('number')) ? $request->input('number') : '';
        if ($number === '') {
            return $this->json(array('error' => attachment()->formatFileWrong()));
        }

        // 后处理口径与 box 一致：gallery/main 限宽 image_width，重打水印
        $opts = AttachmentUploadOptions::create()
            ->withImageWidth((int) Config::get('site.image_width', 1000))
            ->withWatermark(Config::get('site.watermark', false))
            ->withUploader('admin', (int) auth('admin')->id());

        $file = UploadedFile::fromGlobals('file');
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
     * 记住「上传时裁剪」勾选（缩略图 / 主图各自一份）。
     *
     * @param Request $request
     * @return \Dou\Core\Web\Http\Response
     */
    public function cropPref(Request $request)
    {
        $on = $request->integer('crop', 0) ? 1 : 0;
        $scope = $request->alpha('scope', 'thumb');
        if ($scope !== 'gallery') {
            $scope = 'thumb';
        }
        Session::set($scope === 'gallery' ? 'gallery_crop' : 'thumb_crop', $on);

        return $this->json(array('crop' => $on, 'scope' => $scope));
    }

    /**
     * @param Request $request
     * @return \Dou\Core\Web\Http\Response
     */
    public function destroy(Request $request)
    {
        $number = preg_match("/^[a-z0-9.]+$/", $request->input('number')) ? $request->input('number') : '';
        $file_info = DB::table('file')->field('module, item_id, type')->where('number', $number)->find();

        if (empty($file_info) || !isset($file_info['module'])) {
            return $this->response('');
        }

        attachment()->delete($number);

        $html = attachment()->gallery($file_info['module'], $file_info['item_id'], $file_info['type']);

        return $this->response($html);
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
        $useDraft = ($draft_token !== '' && $item_id <= 0);
        if ($useDraft) {
            $admin_id = (int) auth('admin')->id();
            if ($admin_id <= 0) {
                return $this->response('');
            }
            $data = attachment()->chunkedStoreDraft($module, 'admin', $admin_id, $draft_token, $file_field, $type, $custom_filename, $file_type);
        } else {
            $data = attachment()->chunkedStore($module, $item_id, $file_field, $type, $custom_filename, $file_type, null, 'admin', (int) auth('admin')->id());
        }

        return $this->json($data);
    }
}
